<?php

use App\Models\SetupState;
use App\Models\User;
use App\Services\FirstRunService;
use App\Services\PalletStock;
use App\Services\Simulator\NameBank;
use App\Services\Simulator\SimRandom;
use App\Services\Simulator\SimulationRun;
use App\Services\Simulator\WarehouseSimulator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

// The simulator needs what a finished first-run wizard leaves behind: the seeded catalog and an administrator.
function setUpEmptyInstance(): void
{
    Storage::fake('local');
    app(FirstRunService::class)->ensureEssentialData();
    app(FirstRunService::class)->createAdministrator('sim-admin@example.com', 'a-good-password-123', 'Sim', 'Admin');
    SetupState::current()->update(['demo_data_seeded_at' => null]);
}

afterEach(fn () => File::delete(SimulationRun::statusPath()));

/** Running usable-stock balance per item, replayed in the order the rows were written. */
function itemsEverNegative(): array
{
    $balance = [];
    $negative = [];
    foreach (DB::table('item_ledgers')->orderBy('created_at')->orderBy('id')->get(['item_id', 'qty_added', 'qty_subtracted', 'disposition']) as $row) {
        $balance[$row->item_id] ??= 0;
        $balance[$row->item_id] += ($row->disposition === 'usable' ? (int) $row->qty_added : 0) - (int) $row->qty_subtracted;
        if ($balance[$row->item_id] < 0) {
            $negative[$row->item_id] = true;
        }
    }

    return array_keys($negative);
}

test('the simulator refuses an instance that already has data', function () {
    setUpEmptyInstance();
    DB::table('drivers')->insert(['name' => 'Someone Real', 'created_at' => now(), 'updated_at' => now()]);

    expect(WarehouseSimulator::blockedReason())->toContain('drivers');
    $this->artisan('simulate:warehouse', ['--days' => 10, '--orders' => 5, '--yes' => true])->assertFailed();
    expect(DB::table('orderdonations')->exists())->toBeFalse();
});

test('a simulated period is realistic and only the injected errors ever go negative', function () {
    setUpEmptyInstance();

    $this->artisan('simulate:warehouse', ['--days' => 30, '--orders' => 100, '--errors' => 2, '--seed' => 11, '--yes' => true])->assertSuccessful();

    $status = json_decode(File::get(SimulationRun::statusPath()), true);
    $summary = $status['summary'];

    expect($status['state'])->toBe('done')
        ->and($summary['counts']['orders'])->toBeGreaterThan(50)
        ->and($summary['counts']['donations'])->toBeGreaterThan(10)
        ->and($summary['counts']['ledger_rows'])->toBeGreaterThan(300)
        ->and($summary['injected_errors'])->toHaveCount(2)
        ->and(SetupState::current()->demo_data_seeded_at)->not->toBeNull();

    // Every on-hand number stays >= 0 at every moment, except for the items an error was injected into.
    expect(itemsEverNegative())->toEqualCanonicalizing(array_values(array_unique(array_column($summary['injected_errors'], 'item_id'))));

    // History is real history: stamped in the past, in order, nothing in the future.
    expect(DB::table('item_ledgers')->where('created_at', '>', now())->count())->toBe(0)
        ->and(DB::table('orderdonations')->whereColumn('status_changed_at', '<', 'created_at')->count())->toBe(0)
        ->and((string) DB::table('item_ledgers')->min('created_at'))->toBeLessThan(now()->subDays(25)->toDateTimeString());

    // Orders reached later lifecycle stages through the normal statuses, and fills are tied to their request line.
    $statuses = array_keys($summary['order_statuses']);
    expect($statuses)->toContain('Completed')
        ->and(DB::table('item_ledgers')->whereNotNull('order_line_id')->where('qty_subtracted', '>', 0)->exists())->toBeTrue()
        ->and(DB::table('item_ledgers')->whereNotNull('pallet_id')->where('qty_added', '>', 0)->exists())->toBeTrue()
        ->and($summary['fill_rate_percent'])->toBeGreaterThan(30);

    // Pallet-level stock is simulated too: many multi-item warehouse pallets, some stock left in the sorting area, and
    // no pallet is ever recorded as holding less than nothing (over-picks hit the sorting-area pool, never a pallet).
    $quantities = PalletStock::quantities();
    expect($summary['counts']['warehouse_pallets'])->toBeGreaterThan(20)
        ->and($summary['counts']['pallet_stock_assignments'])->toBeGreaterThan($summary['counts']['warehouse_pallets'])
        ->and($summary['counts']['stock_still_in_sorting_area'])->toBeGreaterThan(0)
        ->and($quantities->min('qty'))->toBeGreaterThanOrEqual(0)
        ->and(DB::table('item_ledgers')->whereNotNull('stock_pallet_id')->where('qty_subtracted', '>', 0)->exists())->toBeTrue()
        ->and(DB::table('pallets')->where('kind', 'W')->whereIn('status', ['sealed', 'open', 'empty'])->count())->toBe($summary['counts']['warehouse_pallets'])
        ->and(DB::table('pallets')->where('kind', 'W')->where('status', 'open')->count())->toBeGreaterThan(0);

    // Nothing is claimed filled beyond what was requested-or-approved + a legitimately over-picked line.
    expect(DB::table('orderlines')->whereNotNull('qty_approved')->whereColumn('qty_approved', '>', 'qty_requested')->count())->toBe(0);

    // Once it has run, the instance is no longer empty: a second run is refused.
    expect(WarehouseSimulator::blockedReason())->not->toBeNull();
});

test('the same seed produces the same simulation', function () {
    setUpEmptyInstance();

    $run = function () {
        DB::beginTransaction();
        DB::connection('accounts')->beginTransaction();   // the simulated logins
        try {
            $s = app(WarehouseSimulator::class)->run(14, 40, 99, 1);
            unset($s['seconds']);
            foreach ($s['injected_errors'] as &$e) {
                unset($e['order_id']); // ids keep counting across the rolled-back runs
            }

            return $s;
        } finally {
            DB::connection('accounts')->rollBack();
            DB::rollBack();
        }
    };

    expect($run())->toEqual($run());
});

test('zero errors means nothing ever goes negative', function () {
    setUpEmptyInstance();

    app(WarehouseSimulator::class)->run(21, 60, 5, 0);

    expect(itemsEverNegative())->toBe([]);
});

test('a failed run rolls everything back', function () {
    setUpEmptyInstance();
    // Break the run midway: the catalog is fine but there will be no orderable item types to draw from.
    DB::table('itemtypes')->update(['status' => 'retired']);

    expect(fn () => app(WarehouseSimulator::class)->run(10, 10, 1, 0))->toThrow(RuntimeException::class);
    expect(DB::table('people')->whereNotNull('source_system')->count())->toBe(0)
        ->and(DB::table('locations')->count())->toBe(0);
});

test('names come from the bank and are seed-stable', function () {
    // mt_srand is process-global, so run one seeded sequence at a time.
    $names = function (int $seed) {
        $bank = new NameBank(new SimRandom($seed));

        return [$bank->businessName(), $bank->partnerName(), $bank->firstName(), $bank->lastName(), $bank->place()['zip']];
    };

    $first = $names(3);
    expect($names(3))->toBe($first)
        ->and(NameBank::FIRST_NAMES)->toContain($first[2])
        ->and(NameBank::LAST_NAMES)->toContain($first[3]);

    // Business names are adjective + adjective + noun + a business suffix: at least four words.
    expect(count(explode(' ', $first[0])))->toBeGreaterThanOrEqual(4);
});

test('the wizard can start the simulation and lands on its progress page', function () {
    SetupState::current()->update(['completed_at' => null, 'demo_data_seeded_at' => null]);
    Process::fake();

    $this->post('/install', [
        'first_name' => 'Pat', 'last_name' => 'Admin', 'email' => 'pat@example.com',
        'password' => 'a-good-password-123', 'password_confirmation' => 'a-good-password-123',
        'starting_data' => 'simulation',
    ])->assertRedirect('/setup/testing');

    Process::assertRan(fn ($p) => str_contains($p->command, 'simulate:warehouse') && str_contains($p->command, '--yes'));
    // the data itself comes from the detached process, so the wizard added none
    expect(DB::table('orderdonations')->exists())->toBeFalse()
        ->and(json_decode(File::get(SimulationRun::statusPath()), true)['state'])->toBe('running');
});

test('testing tools are for system administrators only', function () {
    $this->actingAs(userWithPermissions('manage-people'));
    $this->get('/setup/testing')->assertForbidden();
    $this->getJson('/json/testing/status')->assertForbidden();
    $this->postJson('/json/testing/simulation', ['days' => 30, 'orders' => 50, 'errors' => 1])->assertForbidden();
});

test('the testing tools status offers the simulator only while the instance is empty', function () {
    setUpEmptyInstance();
    $this->withoutVite(); // the page is only in the Vite manifest after `npm run build`
    $this->actingAs(User::where('email', 'sim-admin@example.com')->firstOrFail());

    $this->get('/setup/testing')->assertOk();
    $this->getJson('/json/testing/status')->assertOk()->assertJsonPath('can_start', true)->assertJsonPath('simulation.state', 'none');

    DB::table('drivers')->insert(['name' => 'Real Driver', 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson('/json/testing/status')->assertJsonPath('can_start', false);
    $this->postJson('/json/testing/simulation', ['days' => 30, 'orders' => 50, 'errors' => 1])->assertStatus(409);
});

test('a simulation process that died does not read as running forever', function () {
    File::put(SimulationRun::statusPath(), json_encode(['state' => 'running', 'pid' => 999999999, 'started_at' => now()->subMinute()->toIso8601String()]));

    expect(app(SimulationRun::class)->status()['state'])->toBe('failed');
});

test('a detached run that is refused at the door says why instead of staying at "Starting"', function () {
    setUpEmptyInstance();
    DB::table('drivers')->insert(['name' => 'Real Driver', 'created_at' => now(), 'updated_at' => now()]);
    // what SimulationRun::start() leaves behind before it launches the process
    File::put(SimulationRun::statusPath(), json_encode(['state' => 'running', 'pid' => null, 'started_at' => now()->toIso8601String()]));

    $this->artisan('simulate:warehouse', ['--days' => 10, '--orders' => 5, '--yes' => true])->assertFailed();

    $status = app(SimulationRun::class)->status();
    expect($status['state'])->toBe('failed')->and($status['error'])->toContain('drivers');
});

test('the detached run is started with the configured php binary, not the web server binary', function () {
    // Under FrankenPHP (the Docker image) PHP_BINARY is `frankenphp`, which can't run artisan.
    config(['backup.php_binary' => '/usr/local/bin/php-cli-test']);
    Process::fake();

    expect(app(SimulationRun::class)->start(30, 50, 1, 0))->toBeTrue();

    Process::assertRan(fn ($p) => str_contains($p->command, "'/usr/local/bin/php-cli-test' artisan simulate:warehouse") && ! str_contains($p->command, PHP_BINARY.' artisan'));
});
