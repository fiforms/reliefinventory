<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\PeopleRoles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Non-interactive first-administrator creation, for the Docker image's
 * entrypoint (user:create prompts for a password, which a container has no
 * terminal to answer). Does nothing once any Administrator exists, so it is
 * safe to run on every start.
 */
class BootstrapAdminCommand extends Command
{
    protected $signature = 'admin:bootstrap
        {--e|email= : Administrator e-mail address (required)}
        {--password= : Password; a random one is generated and printed once if omitted}
        {--fn|firstname=Admin : First name}
        {--ln|lastname=User : Last name}';

    protected $description = 'Create the first Administrator if none exists (non-interactive; used by the Docker entrypoint).';

    // Same role id user:create grants (the seeded Administrator role).
    private const ADMINISTRATOR_ROLE_ID = 15;

    public function handle(): int
    {
        if (PeopleRoles::where('role_id', self::ADMINISTRATOR_ROLE_ID)->exists()) {
            $this->info('An Administrator already exists; nothing to do.');

            return self::SUCCESS;
        }

        $email = $this->option('email');
        if (! $email) {
            $this->error('No Administrator exists yet and no --email was given.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error("A user with e-mail $email already exists; run user:promote to make them an Administrator.");

            return self::FAILURE;
        }

        $generated = ! $this->option('password');
        $password = $generated ? Str::password(20, symbols: false) : $this->option('password');

        $user = new User;
        $user->email = $email;
        $user->first_name = $this->option('firstname');
        $user->last_name = $this->option('lastname');
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->approved_at = now();
        $user->save();

        PeopleRoles::create(['person_id' => $user->id, 'role_id' => self::ADMINISTRATOR_ROLE_ID]);

        $this->info("Created Administrator $email (user id {$user->id}).");
        if ($generated) {
            $this->warn("Generated password (shown once — change it after signing in): $password");
        }

        return self::SUCCESS;
    }
}
