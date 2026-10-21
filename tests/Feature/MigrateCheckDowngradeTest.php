<?php

use Illuminate\Support\Facades\DB;

it('passes when every applied migration is known', function () {
    $this->artisan('migrate:check-downgrade')->assertExitCode(0);
});

it('fails when the database has a migration this code does not ship', function () {
    DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_the_future', 'batch' => 999]);

    $this->artisan('migrate:check-downgrade')
        ->expectsOutputToContain('NEWER version')
        ->assertExitCode(1);
});
