<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\BannerSetting;

test('no banner is active by default', function () {
    $user = userWithPermissions('general-access');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page->where('banner.active', false));
});

test('updating banner settings requires manage-feedback', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->putJson('/json/banner-settings', [
        'type' => 'message',
        'message' => 'Hello',
    ])->assertForbidden();
});

test('a manage-feedback holder can set the banner and it appears for other users', function () {
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->putJson('/json/banner-settings', [
        'type' => 'message',
        'message' => 'We will be adding a new feature soon.',
    ])->assertOk();

    $other = userWithPermissions('general-access');
    $response = $this->actingAs($other)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('banner.active', true)
        ->where('banner.type', 'message')
        ->where('banner.message', 'We will be adding a new feature soon.'));
});

test('dismissing the banner hides it for that user until content changes', function () {
    $admin = userWithPermissions('manage-feedback', 'general-access');

    $this->actingAs($admin)->putJson('/json/banner-settings', [
        'type' => 'maintenance',
        'message' => 'Maintenance tonight 10pm-11pm.',
    ])->assertOk();

    $version = BannerSetting::current()->version;

    $this->actingAs($admin)->postJson('/json/banner-dismiss', ['version' => $version])->assertOk();

    $response = $this->actingAs($admin)->get('/dashboard');
    $response->assertInertia(fn ($page) => $page->where('banner.active', false));

    // Editing the message bumps the version, so it reappears.
    $this->actingAs($admin)->putJson('/json/banner-settings', [
        'type' => 'maintenance',
        'message' => 'Maintenance extended to midnight.',
    ])->assertOk();

    $response = $this->actingAs($admin)->get('/dashboard');
    $response->assertInertia(fn ($page) => $page->where('banner.active', true));
});
