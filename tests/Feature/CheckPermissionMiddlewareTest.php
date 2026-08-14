<?php

use App\Models\User;

test('a route gated by a permission rejects a user without it', function () {
    $user = User::factory()->create(); // no permissions at all

    $this->actingAs($user)->getJson('/json/items')->assertStatus(403);
});

test('a route gated by a permission allows a user with it', function () {
    $user = userWithPermissions('manage-items');

    $this->actingAs($user)->getJson('/json/items')->assertOk();
});

test('an unauthenticated request is rejected before permission checks run', function () {
    $this->getJson('/json/items')->assertStatus(401);
});

test('admin-tier routes require the specific admin permission, not just the manage-tier one', function () {
    $user = userWithPermissions('manage-people'); // volunteer-tier only

    $this->actingAs($user)->deleteJson('/json/people/999999')->assertStatus(403);
});
