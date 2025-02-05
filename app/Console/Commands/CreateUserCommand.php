<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Implements "artisan user:create" command

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create {--u|username= : Username of the newly created user.} {--e|email= : E-Mail of the newly created user.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually creates a new laravel user.';

    /**
     * Execute the console command.
     * https://laravel.com/docs/9.x/artisan
     *
     * @return int
     */
    public function handle()
    {
        // Enter username, if not present via command line option
        $name = $this->option('username');
        if ($name === null) {
            $name = $this->ask('Please enter your username.');
        }

        // Enter email, if not present via command line option
        $email = $this->option('email');
        if ($email === null) {
            $email = $this->ask('Please enter your E-Mail.');
        }

        // Always enter password from userinput for more security.
        $password = $this->secret('Please enter a new password.');
        $password_confirmation = $this->secret('Please confirm the password');

	if($password != $password_confirmation) {
		$this->error('Passwords Do Not Match');
		return;
	}

        try {
	    $user = new User();
	    $user->password = Hash::make($password);
	    $user->email = $email;
	    $user->name = $name;
	    $user->save();
        }
        catch (\Exception $e) {
            $this->error($e->getMessage());
            return;
        }

        // Success message
        $this->info('User created successfully!');
        $this->info('New user id: ' . $user->id);
    }
}
