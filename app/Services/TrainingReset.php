<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Http\Controllers\TrainingAdminController;
use App\Http\Controllers\TrainingReportController;
use App\Models\Accounts\Account;
use App\Models\ActivityLog;
use App\Models\Control\Warehouse;
use App\Models\TrainingSetting;
use App\Models\User;
use App\Services\Warehouses\WarehouseContext;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Resetting a training warehouse (MULTI_WAREHOUSE.md §12): drop everything in *that one warehouse* and build it again from its seed.
 * It is the provisioning path with the warehouse's own database user, so there is no dump, no restore, no pre-class backup and
 * no maintenance mode for anyone else: only this warehouse is unavailable while it is rebuilt (status `restoring`).
 *
 *  1. Save the **class archive** (sign-ups list, report cards PDF + CSV, certificates) to the warehouse's files, where a reset keeps it.
 *  2. Write a **snapshot** of what outlives a reset to this warehouse's own folder outside the database: the people who log in
 *     (name, account, roles; their login stays in the accounts schema and so does their membership) and the certificate details
 *     (event, trainers, signatures). A crashed reset is finished from the snapshot by running it again.
 *  3. `db:wipe`, `migrate`, the essentials, mark setup complete; put the people back (new person rows, same accounts and roles,
 *     memberships pointed at them) and the certificate details; delete every upload except the archives and signatures.
 *  4. Back to `active`.
 *
 * Everything else is gone: practice records, trainees, sign-ups, the activity log, items added to the catalog, approved
 * devices. Progress is in <backup dir>/warehouses/<id>/training/reset-status.json, since the database is replaced part-way.
 */
class TrainingReset
{
    public const ARCHIVE_DIR = 'training-archives';

    /** The files a class archive may hold (also the only names the download route serves). */
    public const ARCHIVE_FILES = [
        'signups.csv' => 'Sign-up follow-up list (CSV)',
        'report-cards.pdf' => 'Report cards (PDF)',
        'report-cards.csv' => 'Report cards (CSV)',
        'certificates.pdf' => 'Certificates (PDF)',
    ];

    public function dir(): string
    {
        return rtrim(config('backup.dir'), '/').'/warehouses/'.app(WarehouseContext::class)->current()->id.'/training';
    }

    public function status(): array
    {
        $raw = @file_get_contents($this->dir().'/reset-status.json');

        return ($raw !== false ? json_decode($raw, true) : null) ?: ['state' => 'idle'];
    }

    /** A reset whose process died long ago (no status written for 30 minutes) no longer blocks another. */
    public function isRunning(): bool
    {
        $status = $this->status();

        return $status['state'] === 'running' && now()->diffInMinutes(Carbon::parse($status['started_at'] ?? 0), true) < 30;
    }

    private function snapshotPath(): string
    {
        return $this->dir().'/reset-snapshot.json';
    }

    /** Save the class archive, then start the detached reset. Returns the archive's stamp. */
    public function start(): string
    {
        $this->assertTraining();
        if ($this->isRunning()) {
            throw new RuntimeException('A reset is already running.');
        }

        $stamp = $this->archive();
        $this->writeStatus(['state' => 'running', 'started_at' => now()->toIso8601String(), 'archive' => $stamp]);
        $this->launch($stamp);

        return $stamp;
    }

    /** Starts `training:reset` for this warehouse in its own session, so it outlives the request. */
    protected function launch(string $stamp): void
    {
        $log = escapeshellarg($this->dir().'/reset.log');
        $php = escapeshellarg(config('backup.php_binary'));
        $warehouse = escapeshellarg('--warehouse='.app(WarehouseContext::class)->current()->id);
        Process::path(base_path())->run("setsid -f {$php} artisan training:reset ".escapeshellarg($stamp)." {$warehouse} >> {$log} 2>&1 < /dev/null");
    }

    /**
     * The detached half (training:reset): rebuild this warehouse. On failure the warehouse stays `restoring` (half rebuilt, so
     * nobody may use it) with the reason in the status file; running the reset again finishes it from the snapshot.
     */
    public function run(string $stamp): void
    {
        $this->assertTraining();
        $warehouse = app(WarehouseContext::class)->current()->refresh();   // the status as it is now, not as a caller last saw it
        $previous = $warehouse->status === 'restoring' ? 'active' : $warehouse->status;
        try {
            if (! is_file($this->snapshotPath())) {
                $this->writeSnapshot();
            }
            $warehouse->forceFill(['status' => 'restoring'])->save();

            $this->rebuild();
            $this->putBack(json_decode((string) file_get_contents($this->snapshotPath()), true));
            $this->clearUploads();
            @unlink($this->snapshotPath());

            $warehouse->forceFill(['status' => $previous])->save();
            $this->writeStatus(['state' => 'complete', 'finished_at' => now()->toIso8601String(), 'archive' => $stamp]);
        } catch (Throwable $e) {
            Log::error('Training reset failed', ['warehouse' => $warehouse->id, 'message' => $e->getMessage()]);
            $this->writeStatus(['state' => 'failed', 'finished_at' => now()->toIso8601String(), 'archive' => $stamp, 'error' => $e->getMessage()]);
        }
    }

    private function assertTraining(): void
    {
        if (! TrainingMode::active()) {
            throw new RuntimeException('Only a training warehouse can be reset.');
        }
    }

    /** What outlives a reset, written outside the database before anything is wiped. */
    private function writeSnapshot(): void
    {
        $members = [];
        foreach (User::whereNotNull('account_id')->where('is_trainee', false)->get() as $person) {
            $members[] = [
                'account_id' => $person->account_id,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'roles' => $person->roles()->pluck('roles.name')->all(),
            ];
        }
        $settings = TrainingSetting::current()->only(['event_title', 'event_location', 'trainer1_name', 'trainer1_signature', 'trainer2_name', 'trainer2_signature']);

        @mkdir($this->dir(), 0750, true);
        file_put_contents($this->snapshotPath(), json_encode(['members' => $members, 'settings' => $settings]));
    }

    /** Empty schema, current migrations, the essentials, and the setup latch (so /install never opens here). */
    private function rebuild(): void
    {
        foreach ([['db:wipe', ['--force' => true]], ['migrate', ['--force' => true]]] as [$command, $args]) {
            if (Artisan::call($command, $args) !== 0) {
                throw new RuntimeException("{$command} failed: ".trim(Artisan::output()));
            }
        }
        $firstRun = app(FirstRunService::class);
        $firstRun->ensureEssentialData();
        app(PermissionsSeeder::class)->setContainer(app())->__invoke();
        $firstRun->markComplete();
    }

    /** The people who log in come back as new person rows on the same accounts and roles; the memberships follow them. */
    private function putBack(array $snapshot): void
    {
        $warehouse = app(WarehouseContext::class)->current();
        foreach ($snapshot['members'] as $member) {
            $account = Account::find($member['account_id']);
            if (! $account) {
                continue;
            }
            $person = new User;
            $person->forceFill([
                'first_name' => $member['first_name'], 'last_name' => $member['last_name'], 'email' => $account->email,
                'account_id' => $account->id, 'approved_at' => now(),
            ])->save();
            foreach (DB::table('roles')->whereIn('name', $member['roles'])->pluck('id') as $roleId) {
                DB::table('people_roles')->insert(['person_id' => $person->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $account->memberships()->where('warehouse_id', $warehouse->id)->update(['person_id' => $person->id]);
        }
        TrainingSetting::current()->update($snapshot['settings']);
    }

    /** Uploaded files from the class go; the class archives and the trainers' signatures stay. */
    private function clearUploads(): void
    {
        $disk = Storage::disk('local');
        $keep = [self::ARCHIVE_DIR, 'training-signatures'];
        foreach ($disk->directories() as $directory) {
            if (! in_array($directory, $keep, true)) {
                $disk->deleteDirectory($directory);
            }
        }
        foreach ($disk->files() as $file) {
            if (! str_starts_with(basename($file), '.')) {
                $disk->delete($file);
            }
        }
    }

    /**
     * The class archive: what is worth keeping from the class, as files. Report cards cover the days from the reset
     * point to today. A part that fails to render is left out rather than stopping the reset; the sign-ups always go.
     */
    public function archive(): string
    {
        $stamp = now()->format('Y-m-d_His');
        $dir = self::ARCHIVE_DIR.'/'.$stamp;
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);
        $disk->put("$dir/signups.csv", TrainingAdminController::signupsCsvString());

        $from = Carbon::parse(ActivityLog::min('created_at') ?? now())->toDateString();
        $filters = ['from' => min($from, now()->toDateString()), 'to' => now()->toDateString()];
        $reports = app(TrainingReportController::class);
        $parts = [
            'report-cards.csv' => fn () => $disk->put("$dir/report-cards.csv", $reports->csvString($filters)),
            'report-cards.pdf' => fn () => $reports->pdfBuilder($filters)->save($disk->path("$dir/report-cards.pdf")),
            'certificates.pdf' => fn () => $reports->certificatesBuilder($filters)->save($disk->path("$dir/certificates.pdf")),
        ];
        foreach ($parts as $name => $make) {
            try {
                $make();
            } catch (Throwable $e) {
                Log::warning("Training archive: could not save $name", ['message' => $e->getMessage()]);
            }
        }

        return $stamp;
    }

    /** @return array<int, array{stamp: string, files: array<int, array{name: string, label: string}>}> newest first */
    public function archives(): array
    {
        $disk = Storage::disk('local');
        $out = [];
        foreach ($disk->directories(self::ARCHIVE_DIR) as $path) {
            $stamp = basename($path);
            $files = [];
            foreach (self::ARCHIVE_FILES as $name => $label) {
                if ($disk->exists("$path/$name")) {
                    $files[] = ['name' => $name, 'label' => $label];
                }
            }
            $out[] = ['stamp' => $stamp, 'files' => $files];
        }
        usort($out, fn ($a, $b) => strcmp($b['stamp'], $a['stamp']));

        return $out;
    }

    /** The disk path of one archived file, or null (only known names in a well-formed stamp). */
    public function archiveFile(string $stamp, string $name): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $stamp) || ! isset(self::ARCHIVE_FILES[$name])) {
            return null;
        }
        $path = self::ARCHIVE_DIR."/$stamp/$name";

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    private function writeStatus(array $status): void
    {
        @mkdir($this->dir(), 0750, true);
        file_put_contents($this->dir().'/reset-status.json', json_encode($status));
    }
}
