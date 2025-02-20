<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UpdateUserPasswordCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:update-password {--e|email= : User Email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update an existing user\'s password from the command line.';

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

        // Prompt for the new password
        $password = $this->secret('Please enter the new password');
        $passwordConfirmation = $this->secret('Please confirm the new password');

        // Ensure passwords match
        if ($password !== $passwordConfirmation) {
            $this->error('Passwords do not match.');
            return 1;
        }

        // Update the user's password
        try {
            $user->password = Hash::make($password);
            $user->save();
            $this->info('Password updated successfully for ' . $user->email);
        } catch (\Exception $e) {
            $this->error('Error updating password: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
