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
        // Retrieve all users
        $users = User::select('id', 'first_name', 'last_name', 'email', 'created_at')->get();

        if ($users->isEmpty()) {
            $this->info('No users found.');
            return 0;
        }

        // Display users in a table format
        $this->table(
            ['ID', 'First Name', 'Last Name', 'Email', 'Created At'],
            $users->toArray()
        );

        return 0;
    }
}
