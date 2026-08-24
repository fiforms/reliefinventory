<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\LoginHistory;
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

test('the login history json endpoint requires the admin-system permission', function () {
    $this->actingAs(User::factory()->create())
        ->get('/json/login-history')
        ->assertForbidden();
});

test('login history with no person_id shows only the most recent login per person', function () {
    $ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Active']);
    $bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Busy']);

    LoginHistory::create([
        'person_id' => $ann->id,
        'method' => 'password',
        'ip_address' => '10.0.0.1',
        'user_agent' => 'test',
        'logged_in_at' => now()->subMinute(),
    ]);
    LoginHistory::create([
        'person_id' => $ann->id,
        'method' => 'pin',
        'ip_address' => '10.0.0.2',
        'user_agent' => 'test',
        'logged_in_at' => now(),
    ]);
    LoginHistory::create([
        'person_id' => $bob->id,
        'method' => 'password',
        'ip_address' => '10.0.0.3',
        'user_agent' => 'test',
        'logged_in_at' => now()->subSeconds(30),
    ]);

    $response = $this->actingAs(userWithPermissions('admin-system'))
        ->get('/json/login-history');

    $response->assertOk();
    $rows = collect($response->json('history'));

    expect($rows)->toHaveCount(2);
    expect($rows->first()['name'])->toBe('Ann Active');
    expect($rows->first()['method'])->toBe('pin');
    expect($rows->last()['name'])->toBe('Bob Busy');
});

test('login history with a person_id returns that person\'s full history', function () {
    $person = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Active']);

    LoginHistory::create([
        'person_id' => $person->id,
        'method' => 'password',
        'ip_address' => '10.0.0.1',
        'user_agent' => 'test',
        'logged_in_at' => now()->subMinute(),
    ]);
    LoginHistory::create([
        'person_id' => $person->id,
        'method' => 'pin',
        'ip_address' => '10.0.0.2',
        'user_agent' => 'test',
        'logged_in_at' => now(),
    ]);

    $response = $this->actingAs(userWithPermissions('admin-system'))
        ->get('/json/login-history?person_id='.$person->id);

    $response->assertOk();
    $rows = collect($response->json('history'));

    expect($rows)->toHaveCount(2);
    expect($rows->first()['method'])->toBe('pin');
    expect($rows->last()['method'])->toBe('password');
});

test('a successful password login is recorded in login history', function () {
    $person = User::factory()->create(['password' => bcrypt('secret-password')]);

    $this->post('/login', [
        'email' => $person->email,
        'password' => 'secret-password',
    ])->assertRedirect();

    expect(LoginHistory::where('person_id', $person->id)->where('method', 'password')->exists())->toBeTrue();
});
