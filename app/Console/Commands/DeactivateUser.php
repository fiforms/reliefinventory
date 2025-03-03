<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\PeopleRoles;

class PromoteUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:deactivate {--e|email= : User Email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deactivate a user account';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get email, either from the command line option or prompt the user
        $email = $this->option('email');
        if (!$email) {
            $email = $this->ask('Please enter the user\'s email');
        }

        // Find the user by email
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error('No user found with this email.');
            return 1;
        }
        
        try {
            $user->email_verified_at = null;
            $user->password = null;
            $user->save();
            $this->info('User ' . $user->first_name . ' ' . $user->last_name . ' successfully deactivated');
        } catch (\Exception $e) {
            $this->error('Error deactivating user: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
