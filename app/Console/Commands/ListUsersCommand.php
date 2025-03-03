<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class ListUsersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all users in the system.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Retrieve all users with transformed fields
        $users = User::select('id', 'first_name', 'last_name', 'email', 'role_bitpack', 'created_at', 'email_verified_at', 'password')
        ->get()
        ->map(function ($user) {
            return [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role_bitpack' => $user->role_bitpack,
                'created_at' => $user->created_at->format('Y-m-d'), // Show only the date
                'email_verified' => $user->email_verified_at ? '✔' : '✖', // Show a checkmark or cross
                'password' => $user->password ? '✔' : '✖',
            ];
        });
            
            if ($users->isEmpty()) {
                $this->info('No users found.');
                return 0;
            }
            
            // Display users in a table format
            $this->table(
                ['ID', 'First Name', 'Last Name', 'Email', 'Role Bitpack', 'Created At', 'Verified','Has Login'],
                $users->toArray()
                );
            
            return 0;
    }
    
}
