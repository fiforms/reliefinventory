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
    protected $signature = 'user:promote {--e|email= : User Email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make an existing user into an Administrator (Super User)';

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
            $user->role_bitpack = 32768;
            $user->email_verified_at = now();
            $user->save();
            $role = new PeopleRoles();
            $role->person_id = $user->id;
            $role->role_id = 15;
            $role->save();
            $this->info('User ' . $user->first_name . ' ' . $user->last_name . ' successfully promoted to Super User');
        } catch (\Exception $e) {
            $this->error('Error promoting user: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
