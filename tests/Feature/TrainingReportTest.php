<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// The activity log behind Training Mode's report cards, the report itself, and the training PDFs.

use App\Http\Controllers\Auth\TrainingLoginController;
use App\Http\Controllers\TrainingAdminController;
use App\Http\Controllers\TrainingReportController;
use App\Models\ActivityLog;
use App\Models\Driver;
use App\Models\ItemLedger;
use App\Models\TrainingSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TrainingPreparer;
use App\Support\TrainingGuide;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function preparedTraining(): array
{
    $items = scannedProducts();
    trainingWarehouse();

    test()->actingAs(userWithPermissions('manage-training'));
    $summary = app(TrainingPreparer::class)->run();
    auth()->logout();

    return ['summary' => $summary, 'item' => $items[0]];
}

/** Act as $trainee (a person in this training warehouse). */
function asTrainee(User $user)
{
    return test()->actingAs($user);
}

test('station actions are logged with the trainee and their team; nothing is logged with nobody logged in', function () {
    ['summary' => $summary, 'item' => $item] = preparedTraining();
    $trainee = TrainingLoginController::createTrainee('Ann', 'Able', 2);
    $user = User::find($trainee->id);

    // No one logged in (artisan, the simulator): nothing recorded.
    Transaction::create(['type' => 'donation', 'category' => 'donation', 'order_date' => now()->toDateString(), 'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED)]);
    expect(ActivityLog::count())->toBe(0);

    asTrainee($user);
    Transaction::create(['type' => 'donation', 'category' => 'donation', 'order_date' => now()->toDateString(), 'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED)]);
    $order = Transaction::find($summary['orders_to_fill'][0]['id']);
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    ItemLedger::create(['orderdonation_id' => $order->id, 'item_id' => $item->id, 'order_line_id' => $summary['orders_to_fill'][0]['lines'][0]['line_id'],
        'qty_subtracted' => 1, 'disposition' => 'usable']);
    Transaction::find($summary['orders_to_ship'][0]['id'])->update(['status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_SHIP)]);

    expect(ActivityLog::orderBy('id')->get(['person_id', 'training_team', 'station', 'action'])->toArray())->toBe([
        ['person_id' => $trainee->id, 'training_team' => 2, 'station' => 1, 'action' => 'receiving.intake'],
        ['person_id' => $trainee->id, 'training_team' => 2, 'station' => 4, 'action' => 'picking.started'],
        ['person_id' => $trainee->id, 'training_team' => 2, 'station' => 4, 'action' => 'picking.fill'],
        ['person_id' => $trainee->id, 'training_team' => 2, 'station' => 5, 'action' => 'shipping.driver_assigned'],
    ]);
});

test('a Driver Portal delivery by a team driver counts for that team', function () {
    ['summary' => $summary] = preparedTraining();
    Storage::fake('local');
    $trainee = TrainingLoginController::createTrainee('Bo', 'Baker', 3);
    // The load Team 3 shipped to its own driver at Station 5.
    $driver = Driver::where('training_team', 3)->first();
    $delivery = ['id' => $summary['orders_to_ship'][0]['id']];
    $order = Transaction::find($delivery['id']);
    $order->update(['driver_id' => $driver->id, 'status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_SHIP), 'bol_number' => 'BOL-T3']);
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_SHIPPED)]);

    $this->postJson('/driver-portal/login', ['phone' => $driver->phone, 'pin' => TrainingPreparer::teamDriverPin(3)])->assertOk();
    $this->post('/driver-portal/loads/'.$delivery['id'].'/bol', ['file' => UploadedFile::fake()->image('bol.jpg')])->assertOk();

    $row = ActivityLog::sole();
    expect($row->only(['person_id', 'driver_id', 'training_team', 'station', 'action']))
        ->toBe(['person_id' => null, 'driver_id' => $driver->id, 'training_team' => 3, 'station' => 6, 'action' => 'delivery.bol_uploaded']);

    $card = collect(app(TrainingReportController::class)->buildReport()['trainees'])->firstWhere('person_id', $trainee->id);
    // Only the team delivery — no work of their own — so they aren't listed.
    expect($card)->toBeNull();

    asTrainee(User::find($trainee->id));
    Transaction::create(['type' => 'donation', 'category' => 'donation', 'order_date' => now()->toDateString(), 'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED)]);
    $card = collect(app(TrainingReportController::class)->buildReport()['trainees'])->firstWhere('person_id', $trainee->id);
    expect($card['stations'][1]['done'])->toBeTrue()
        ->and($card['stations'][6]['done'])->toBeTrue()
        ->and($card['stations'][6]['actions'][0]['label'])->toBe('Delivered (uploaded a signed BOL)')
        ->and($card['done'])->toBe(2)
        ->and($card['complete'])->toBeFalse();
});

test('a trainee who worked all six stations is complete and gets a certificate', function () {
    $trainee = TrainingLoginController::createTrainee('Cy', 'Complete', 1);
    $other = TrainingLoginController::createTrainee('Di', 'Partial', 4);
    foreach (range(1, 6) as $station) {
        ActivityLog::create(['person_id' => $trainee->id, 'training_team' => 1, 'station' => $station, 'action' => 'x.y']);
    }
    ActivityLog::create(['person_id' => $other->id, 'training_team' => 4, 'station' => 2, 'action' => 'sorting.line']);
    // Another day doesn't count toward today's class.
    ActivityLog::create(['person_id' => $other->id, 'training_team' => 4, 'station' => 3, 'action' => 'inventory.put_on_pallet'])
        ->forceFill(['created_at' => now()->subDays(2)])->save();

    $report = app(TrainingReportController::class)->buildReport();
    expect($report['totals'])->toBe(['trainees' => 2, 'complete' => 1])
        ->and(collect($report['trainees'])->firstWhere('person_id', $other->id)['done'])->toBe(1)
        ->and(app(TrainingReportController::class)->buildReport(['team' => 4])['trainees'])->toHaveCount(1);

    $path = tempnam(sys_get_temp_dir(), 'cert').'.pdf';
    app(TrainingReportController::class)->certificatesBuilder([])->save($path);
    $text = pdfText($path);
    expect($text)->toContain('Certificate of Completion')->toContain('Cy Complete')->not->toContain('Di Partial');
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('the certificate shows the event title and location set on the Training Mode page', function () {
    $trainee = TrainingLoginController::createTrainee('Cy', 'Complete', 1);
    foreach (range(1, 6) as $station) {
        ActivityLog::create(['person_id' => $trainee->id, 'training_team' => 1, 'station' => $station, 'action' => 'x.y']);
    }

    $this->actingAs(userWithPermissions('general-access'))
        ->putJson('/json/training/event', ['event_title' => 'X', 'event_location' => 'Y'])->assertForbidden();
    $this->actingAs(userWithPermissions('manage-training'))
        ->putJson('/json/training/event', ['event_title' => ' Fall Warehouse Class ', 'event_location' => 'Statesville Warehouse'])
        ->assertOk()->assertJson(['event_title' => 'Fall Warehouse Class', 'event_location' => 'Statesville Warehouse']);

    $path = tempnam(sys_get_temp_dir(), 'cert').'.pdf';
    app(TrainingReportController::class)->certificatesBuilder([])->save($path);
    expect(pdfText($path))->toContain('Fall Warehouse Class')->toContain('held at Statesville Warehouse')
        ->not->toContain('Receiving');
    expect((int) preg_replace('/\D/', '', shell_exec('pdfinfo '.escapeshellarg($path).' | grep Pages')))->toBe(1);

    // Blank fields fall back to the default title and leave the location out.
    $this->putJson('/json/training/event', ['event_title' => '', 'event_location' => null])->assertOk()
        ->assertJson(['event_title' => null, 'event_location' => null]);
    app(TrainingReportController::class)->certificatesBuilder([])->save($path);
    expect(pdfText($path))->toContain('Hands-on Warehouse Training')->not->toContain('held at');
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('trainer signatures upload, print on the certificate side by side, and can be removed', function () {
    Storage::fake('local');
    $trainee = TrainingLoginController::createTrainee('Cy', 'Complete', 1);
    foreach (range(1, 6) as $station) {
        ActivityLog::create(['person_id' => $trainee->id, 'training_team' => 1, 'station' => $station, 'action' => 'x.y']);
    }

    $this->actingAs(userWithPermissions('general-access'))
        ->post('/json/training/signatures/1', ['signature' => UploadedFile::fake()->image('sig.png')])->assertForbidden();

    $this->actingAs(userWithPermissions('manage-training'));
    $this->putJson('/json/training/event', ['trainer1_name' => 'Pat Morgan', 'trainer2_name' => 'Chris Lee'])->assertOk();
    $this->postJson('/json/training/signatures/3', ['signature' => UploadedFile::fake()->image('sig.png')])->assertNotFound();
    $this->postJson('/json/training/signatures/1', ['signature' => UploadedFile::fake()->create('sig.svg', 1, 'image/svg+xml')])
        ->assertUnprocessable();
    $this->postJson('/json/training/signatures/1', ['signature' => UploadedFile::fake()->image('sig.png', 300, 80)])
        ->assertOk()->assertJsonPath('trainers.0.has_signature', true)->assertJsonPath('trainers.1.has_signature', false);
    $first = TrainingSetting::current()->trainer1_signature;
    Storage::disk('local')->assertExists($first);
    $this->get('/json/training/signatures/1')->assertOk();
    $this->get('/json/training/signatures/2')->assertNotFound();

    // Replacing removes the old file.
    $this->postJson('/json/training/signatures/1', ['signature' => UploadedFile::fake()->image('sig.jpg', 300, 80)])->assertOk();
    Storage::disk('local')->assertMissing($first);

    $path = tempnam(sys_get_temp_dir(), 'cert').'.pdf';
    app(TrainingReportController::class)->certificatesBuilder([])->save($path);
    expect(pdfText($path))->toContain('Pat Morgan')->toContain('Chris Lee')->not->toContain('Trainer');
    expect((int) shell_exec('pdfimages -list '.escapeshellarg($path)." | awk '\$3 == \"image\"' | wc -l"))->toBe(2); // logo + one signature (not their alpha masks)

    $this->deleteJson('/json/training/signatures/1')->assertOk()->assertJsonPath('trainers.0.has_signature', false);
    Storage::disk('local')->assertMissing(TrainingSetting::current()->getOriginal('trainer1_signature') ?? 'none');
    expect(TrainingSetting::current()->trainer1_signature)->toBeNull();
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('the training pages and PDFs are gated and render', function () {
    preparedTraining();
    $trainer = userWithPermissions('manage-training');
    $staff = userWithPermissions('general-access');

    $this->actingAs($staff)->getJson('/json/training/status')->assertForbidden();
    $this->actingAs($trainer)->get('/setup/training')->assertInertia(fn ($page) => $page->component('Setup/Training'));
    $this->actingAs($trainer)->get('/reports/training')->assertInertia(fn ($page) => $page->component('TrainingReport'));
    $this->actingAs($staff)->get('/help')->assertInertia(fn ($page) => $page->has('trainingTeams', 5));
    $this->actingAs($trainer)->getJson('/json/training/status')->assertOk()
        ->assertJsonPath('prepared', true)->assertJsonMissingPath('enabled')->assertJsonMissingPath('reset_point');

    $this->actingAs($trainer)->postJson('/json/training/trainees', ['first_name' => 'Ed', 'last_name' => 'East', 'team' => 5])
        ->assertOk()->assertJsonPath('trainees.0.full_name', 'Ed East');

    foreach (['/report/training/setup-packet.pdf' => 'Training Setup Packet', '/report/training/report-cards.pdf' => 'Training Report Cards'] as $url => $title) {
        $this->actingAs($trainer)->get($url)->assertOk();
    }
    $this->actingAs($trainer)->get('/report/training/report-cards.pdf?team=3')->assertOk();
    $this->actingAs($trainer)->get('/report/training/report-cards.csv')->assertOk();
    $this->actingAs($trainer)->get('/report/training/pallet-labels.pdf')->assertOk();
    $this->actingAs($staff)->get('/report/training/setup-packet.pdf')->assertForbidden();
    $this->actingAs($staff)->get('/report/training/team-sheets.pdf?team=2')->assertOk();
});

test('team sheets rotate each team through all six stations and carry its driver login', function () {
    preparedTraining();
    $path = tempnam(sys_get_temp_dir(), 'sheet').'.pdf';
    app(TrainingAdminController::class)->teamSheetsBuilder(2)->save($path);
    $text = pdfText($path);

    expect($text)->toContain('Team 2 — Activity Sheet')
        ->toContain('PIN '.TrainingPreparer::teamDriverPin(2))
        ->toContain('Station 2: Sorting')->toContain('Station 1: Receiving');
    // Team 2 starts at station 2 and ends at station 1.
    expect(TrainingGuide::sheetFor(2)['slots'][0]['station'])->toBe(2)
        ->and(TrainingGuide::sheetFor(2)['slots'][5]['station'])->toBe(1)
        ->and(array_column(TrainingGuide::sheetFor(5)['slots'], 'station'))->toBe([5, 6, 1, 2, 3, 4]);

    app(TrainingAdminController::class)->setupPacketBuilder()->save($path);
    expect(pdfText($path))->toContain('Training Setup Packet')->toContain('Team 1 Driver');
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');
