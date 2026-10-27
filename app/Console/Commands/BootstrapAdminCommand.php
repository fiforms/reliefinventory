<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Accounts\Account;
use App\Models\User;
use App\Services\FirstRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Non-interactive first-administrator creation, for the Docker image's
 * entrypoint (user:create prompts for a password, which a container has no
 * terminal to answer) and for unattended installs. Does nothing once any
 * Administrator exists, so it is safe to run on every start. The first-run web
 * wizard (/install) is the interactive alternative.
 */
class BootstrapAdminCommand extends Command
{
    protected $signature = 'admin:bootstrap
        {--e|email= : Administrator e-mail address (required)}
        {--password= : Password; a random one is generated and printed once if omitted}
        {--fn|firstname=Admin : First name}
        {--ln|lastname=User : Last name}';

    protected $description = 'Create the first Administrator if none exists (non-interactive; used by the Docker entrypoint).';

    public function handle(FirstRunService $firstRun): int
    {
        if ($firstRun->administratorExists()) {
            $firstRun->markComplete();
            $this->info('An Administrator already exists; nothing to do.');

            return self::SUCCESS;
        }

        $email = $this->option('email');
        if (! $email) {
            $this->error('No Administrator exists yet and no --email was given.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists() || Account::findByEmail($email)) {
            $this->error("A user with e-mail $email already exists; run user:promote to make them an Administrator.");

            return self::FAILURE;
        }

        $generated = ! $this->option('password');
        $password = $generated ? Str::password(20, symbols: false) : $this->option('password');

        $firstRun->ensureEssentialData();
        $user = $firstRun->createAdministrator($email, $password, $this->option('firstname'), $this->option('lastname'));

        $this->info("Created Administrator $email (user id {$user->id}).");
        if ($generated) {
            $this->warn("Generated password (shown once — change it after signing in): $password");
        }

        return self::SUCCESS;
    }
}
