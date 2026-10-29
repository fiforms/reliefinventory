<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

test('a json call with no session is marked so the page goes to the login screen', function () {
    $this->withHeader('Referer', 'http://localhost/setup/system?tab=updates')
        ->postJson('/json/system/check-updates')
        ->assertUnauthorized()
        ->assertHeader('X-RI-Login-Required', '1')
        ->assertSessionHas('url.intended', '/setup/system?tab=updates');

    $this->get('/login')->assertInertia(fn ($page) => $page
        ->where('status', 'Your session timed out. Please log in again.'));
});

test('a referer from another site is not remembered as the page to return to', function () {
    $this->withHeader('Referer', 'https://evil.example/phish')
        ->postJson('/json/system/check-updates')
        ->assertUnauthorized()
        ->assertSessionMissing('url.intended');
});

test('a page visit with no session still redirects to login as before', function () {
    $this->get('/setup/system')->assertRedirect('/login');
});

test('other 401s are not marked as a timed-out session', function () {
    $this->postJson('/api/devices/heartbeat')
        ->assertUnauthorized()
        ->assertHeaderMissing('X-RI-Login-Required');
});
