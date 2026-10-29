<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Accounts\Account;
use App\Services\FirstRunService;
use App\Services\Warehouses\WarehouseAccounts;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// Central accounts (MULTI_WAREHOUSE.md §6, phase 0b): every login-capable person's email, password hash and
// verification move to the accounts schema, with a membership for this warehouse, and people.account_id
// links back. Login-capable = has a password, or has a verified email (an admin-created invitation that
// hasn't been accepted yet). Never trainees (their trainee-…@training.invalid emails are not logins), never
// donors or partners with only a contact email. Administrator role holders become instance admins.
//
// An email that already has an account keeps that account untouched (keep-what-is-there) and only gains the
// membership. Who got an account is written to storage/logs/accounts-migration-<schema>-<time>.log.
//
// Runs again when an older backup is restored and migrated. When that restore is untrusted (a warehouse admin's, a setup-code
// holder's: WarehouseAccounts::$untrustedRestore) the dump must not attach anyone's login (§6): this migration then makes no accounts
// and no memberships, only remembers who the dump says could log in (WarehouseAccounts::$legacyLogins), and the restore's relink turns
// those into invitations / unverified new logins, never instance admins.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->unique()->after('id');
        });

        $warehouse = app(WarehouseContext::class)->current();
        $admins = DB::table('people_roles')->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->pluck('person_id')->flip();
        $accounts = DB::connection('accounts');
        $report = [];

        $people = DB::table('people')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->whereNull('is_trainee')->orWhere('is_trainee', false))
            ->where(fn ($q) => $q->whereNotNull('password')->orWhereNotNull('email_verified_at'))
            ->orderBy('id')
            ->get(['id', 'email', 'password', 'email_verified_at']);

        if (WarehouseAccounts::$untrustedRestore) {
            WarehouseAccounts::$legacyLogins = $people->map(fn ($p) => [
                'person_id' => $p->id, 'email' => $p->email, 'password' => $p->password, 'email_verified_at' => null,
            ])->all();
            $people = collect();
        }

        foreach ($people as $person) {
            $existing = Account::findByEmail($person->email);
            if ($existing && $accounts->table('memberships')->where('account_id', $existing->id)->where('warehouse_id', $warehouse->id)->exists()) {
                $report[] = "person {$person->id} <{$person->email}>: SKIPPED, that account already belongs to another person in this warehouse";

                continue;
            }

            if ($existing) {
                $accountId = $existing->id;
                $report[] = "person {$person->id} <{$person->email}>: joined the existing account {$accountId} (its password and details were kept)";
            } else {
                $isAdmin = $admins->has($person->id);
                $accountId = $accounts->table('accounts')->insertGetId([
                    'email' => $person->email,
                    'password' => $person->password,
                    'email_verified_at' => $person->email_verified_at,
                    'is_instance_admin' => $isAdmin,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $report[] = "person {$person->id} <{$person->email}>: new account {$accountId}"
                    .($person->password ? '' : ' (invited, no password yet)').($isAdmin ? ', instance admin' : '');
            }

            $accounts->table('memberships')->insert([
                'account_id' => $accountId,
                'warehouse_id' => $warehouse->id,
                'person_id' => $person->id,
                'status' => 'active',
                'is_training' => $warehouse->is_training,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('people')->where('id', $person->id)->update(['account_id' => $accountId]);
        }

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['password', 'email_verified_at']);
        });
        // Reset tokens now live in the accounts schema; any in flight here are simply void.
        Schema::dropIfExists('password_reset_tokens');

        if ($report && ! WarehouseAccounts::$untrustedRestore) {
            $file = storage_path('logs/accounts-migration-'.$warehouse->schema_name.'-'.now()->format('Ymd-His').'.log');
            File::ensureDirectoryExists(dirname($file));
            File::put($file, "Accounts created for warehouse {$warehouse->id} ({$warehouse->schema_name}):\n".implode("\n", $report)."\n");
        }
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $warehouseId = app(WarehouseContext::class)->current()->id;
        $accounts = DB::connection('accounts');
        foreach (DB::table('people')->whereNotNull('account_id')->get(['id', 'account_id']) as $person) {
            $account = $accounts->table('accounts')->find($person->account_id);
            if ($account) {
                DB::table('people')->where('id', $person->id)->update([
                    'password' => $account->password,
                    'email_verified_at' => $account->email_verified_at,
                ]);
            }
        }
        $accounts->table('memberships')->where('warehouse_id', $warehouseId)->delete();

        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
