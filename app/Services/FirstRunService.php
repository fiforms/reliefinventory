<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\PeopleRoles;
use App\Models\SetupState;
use App\Models\User;
use App\Services\Simulator\SimulationRun;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\WarehouseActivitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Everything behind the first-run setup wizard (and the CLI paths that do the
 * same job: admin:bootstrap, setup:seed-essentials, setup:status).
 *
 * "Needs setup" means: the setup_state latch is unset AND no Administrator
 * exists. The latch is what keeps a working instance from ever being offered
 * to a stranger again — see the create_setup_state_table migration.
 */
class FirstRunService
{
    // Same role id user:create grants (seeded by the reseed_roles_table migration).
    public const ADMINISTRATOR_ROLE_ID = 15;

    public function needsSetup(): bool
    {
        try {
            $state = SetupState::query()->find(1);
        } catch (QueryException) {
            // Not migrated yet — let the normal error/maintenance paths handle that.
            return false;
        }

        if (! $state || $state->completed_at) {
            return false;
        }

        // An Administrator created any other way (user:create, admin:bootstrap,
        // a restored backup) means setup is done; latch it so we stop checking.
        if ($this->administratorExists()) {
            $state->forceFill(['completed_at' => now()])->save();

            return false;
        }

        return true;
    }

    public function administratorExists(): bool
    {
        return PeopleRoles::where('role_id', self::ADMINISTRATOR_ROLE_ID)->exists();
    }

    /**
     * Statuses and categories are only ever filled by the seeders (migrations
     * insert some permission/unit rows themselves, so those can't be the
     * signal) and the statuses seeder is not idempotent — so both empty means
     * "never seeded". Detected from the data, not a marker.
     */
    public function essentialDataSeeded(): bool
    {
        return DB::table('statuses')->exists() || DB::table('categories')->exists();
    }

    /** Seed the lookup data a fresh database needs. Safe to call repeatedly. @return bool whether it seeded */
    public function ensureEssentialData(): bool
    {
        if ($this->essentialDataSeeded()) {
            return false;
        }

        app(DatabaseSeeder::class)->setContainer(app())->__invoke();

        return true;
    }

    /**
     * Create an Administrator (verified and approved, so no mail setup is needed to sign in). The first
     * administrator also runs the instance, so their account is an instance admin (MULTI_WAREHOUSE.md §6); the
     * administrator of a warehouse set up later with a setup code is not ($instanceAdmin = false).
     */
    public function createAdministrator(string $email, string $password, string $firstName, string $lastName, bool $instanceAdmin = true): User
    {
        $user = new User;
        $user->email = $email;
        $user->first_name = $firstName;
        $user->last_name = $lastName;
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->approved_at = now();
        $user->save();
        if ($instanceAdmin) {
            $user->account->forceFill(['is_instance_admin' => true])->save();
        }

        PeopleRoles::create(['person_id' => $user->id, 'role_id' => self::ADMINISTRATOR_ROLE_ID]);
        $this->markComplete();

        return $user;
    }

    public function markComplete(): void
    {
        $state = SetupState::current();
        if (! $state->completed_at) {
            $state->forceFill(['completed_at' => now()])->save();
        }
    }

    /**
     * The example data (WarehouseActivitySeeder), outside any setup transaction: a failure here must leave a working
     * instance with its administrator, not roll back setup. The seeder isn't atomic, so a failure can leave a partial
     * batch — logged, and reported to the caller. @return bool whether it loaded
     */
    public function loadExampleData(): bool
    {
        try {
            app(WarehouseActivitySeeder::class)->setContainer(app())->__invoke();
            SetupState::current()->forceFill(['demo_data_seeded_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            Log::error('First-run example data failed to load', ['exception' => $e]);

            return false;
        }
    }

    /**
     * The wizard's single write: essentials + administrator + latch together
     * (row-locked so two simultaneous submissions can't both win), then the
     * optional example data, or the warehouse simulator (a year of generated
     * activity, run in a detached process — it takes a minute or two, far
     * longer than a request can wait).
     *
     * @param  array{email:string,password:string,first_name:string,last_name:string}  $admin
     * @return array{user: User, demo_failed: bool, simulation_started: bool}
     *
     * @throws RuntimeException if setup was already completed
     */
    public function completeSetup(array $admin, bool $withExampleData, bool $withSimulation = false): array
    {
        $user = DB::transaction(function () use ($admin) {
            $state = SetupState::query()->lockForUpdate()->find(1);
            if (! $state || $state->completed_at || $this->administratorExists()) {
                throw new RuntimeException('Setup has already been completed.');
            }

            $this->ensureEssentialData();
            // Idempotent; guarantees the Administrator role's permission bundle exists.
            app(PermissionsSeeder::class)->setContainer(app())->__invoke();

            return $this->createAdministrator($admin['email'], $admin['password'], $admin['first_name'], $admin['last_name']);
        });

        $demoFailed = false;
        $simulationStarted = false;
        if ($withSimulation && ! $withExampleData) {
            try {
                $simulationStarted = app(SimulationRun::class)->start();
                $demoFailed = ! $simulationStarted;
            } catch (Throwable $e) {
                $demoFailed = true;
                Log::error('First-run simulation could not be started', ['exception' => $e]);
            }
        }

        if ($withExampleData) {
            $demoFailed = ! $this->loadExampleData();
        }

        return ['user' => $user, 'demo_failed' => $demoFailed, 'simulation_started' => $simulationStarted];
    }
}
