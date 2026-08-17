<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the active sessions page requires the admin-system permission', function () {
    $this->actingAs(User::factory()->create())
        ->get('/setup/active-sessions')
        ->assertForbidden();
});

test('a user with admin-system can load the active sessions page', function () {
    $this->actingAs(userWithPermissions('admin-system'))
        ->get('/setup/active-sessions')
        ->assertOk();
});

test('the json endpoint requires the admin-system permission', function () {
    $this->actingAs(User::factory()->create())
        ->get('/json/active-sessions')
        ->assertForbidden();
});

test('active sessions lists people active within the last 15 minutes and excludes stale ones', function () {
    $active = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Active']);
    $stale = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Stale']);

    DB::table('sessions')->insert([
        [
            'id' => 'active-session',
            'user_id' => $active->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'test',
            'last_url' => 'donation-sorting',
            'payload' => 'x',
            'last_activity' => now()->subMinutes(5)->timestamp,
        ],
        [
            'id' => 'stale-session',
            'user_id' => $stale->id,
            'ip_address' => '10.0.0.2',
            'user_agent' => 'test',
            'last_url' => 'receiving',
            'payload' => 'x',
            'last_activity' => now()->subMinutes(30)->timestamp,
        ],
    ]);

    $response = $this->actingAs(userWithPermissions('admin-system'))
        ->get('/json/active-sessions');

    $response->assertOk();
    $names = collect($response->json('sessions'))->pluck('name');

    expect($names)->toContain('Ann Active');
    expect($names)->not->toContain('Sam Stale');
});
