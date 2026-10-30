<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\FirstRunService;
use App\Support\RandomNumberStart;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Makes a new, empty warehouse and leaves it `awaiting_setup` with a setup code (MULTI_WAREHOUSE.md §7): row `provisioning` →
 * schema + database user (the provisioner on Docker, otherwise an administrator credential from config) → key and files
 * folder → migrate → seed the essentials → mark setup complete (so `/install` never runs inside it) → issue the code →
 * `awaiting_setup`, last. Every step is repeatable, so a warehouse stuck in `provisioning` is finished with
 * `provision()` again. No administrator yet: that is the code holder's job (WarehouseSetup).
 */
class WarehouseCreator
{
    /** Extra options for the migrate call (a test passes its schema snapshot). */
    public array $migrateOptions = [];

    public function __construct(
        private WarehouseContext $context,
        private WarehouseLayout $layout,
        private SetupCodes $codes,
        private FirstRunService $firstRun,
    ) {}

    /** @return array{0: Warehouse, 1: string} the warehouse and its setup code (shown once) */
    public function create(string $displayName, ?string $adminEmail = null, ?string $createdBy = null, bool $training = false): array
    {
        $displayName = trim($displayName);
        // Names are unique on the instance (§3): the chooser and the login page show them, so a look-alike would mislead.
        if ($displayName === '' || Warehouse::where('display_name', $displayName)->exists()) {
            throw new \InvalidArgumentException($displayName === '' ? 'A warehouse needs a name.' : "There is already a warehouse called \"{$displayName}\".");
        }

        $id = Warehouse::newId();
        $warehouse = new Warehouse([
            'schema_name' => Provisioner::standardSchemaName($id),
            'display_name' => $displayName,
            'admin_email' => $adminEmail,
            'status' => 'provisioning',
            // Set before it is migrated: the class tools a training warehouse has (and a real one doesn't) key off it (§12).
            'is_training' => $training,
        ]);
        $warehouse->id = $id;
        $warehouse->save();

        return [$warehouse, $this->provision($warehouse, $createdBy)];
    }

    /** Carry a `provisioning` warehouse through to `awaiting_setup`. @return string a fresh setup code */
    public function provision(Warehouse $warehouse, ?string $createdBy = null): string
    {
        if ($warehouse->status !== 'provisioning') {
            throw new \RuntimeException("Warehouse {$warehouse->id} is {$warehouse->status}, not provisioning.");
        }

        $this->createDatabase($warehouse, $createdBy);
        $this->layout->ensure($warehouse);

        $this->context->run($warehouse, function () use ($warehouse) {
            Artisan::call('migrate', ['--force' => true] + $this->migrateOptions);
            $warehouse->forceFill(['migration_state' => 'migrated'])->save();
            $this->firstRun->ensureEssentialData();
            RandomNumberStart::apply();
            app(PermissionsSeeder::class)->setContainer(app())->__invoke();
            $this->firstRun->markComplete();
        });

        [$code] = $this->codes->issue($warehouse, $createdBy);
        $warehouse->forceFill(['status' => 'awaiting_setup'])->save();
        InstanceAuditLog::record('warehouse.created', $warehouse, ['training' => (bool) $warehouse->is_training], $createdBy);

        return $code;
    }

    private function createDatabase(Warehouse $warehouse, ?string $createdBy): void
    {
        if (ProvisioningClient::applies()) {
            app(ProvisioningClient::class)->request($warehouse, 'create', $createdBy);
        } else {
            Provisioner::fromConfig()->create($warehouse);
        }
        // Its password file was written by someone else just now; drop any connection opened before it existed.
        $this->context->forget();
    }
}
