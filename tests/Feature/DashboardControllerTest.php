<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

test('the dashboard requires the view-dashboard permission', function () {
    $user = userWithPermissions('view-reports'); // has other report access, but not view-dashboard

    $this->actingAs($user)->getJson('/json/dashboard')->assertForbidden();
});

test('a user with view-dashboard sees the full metrics payload', function () {
    $user = userWithPermissions('view-dashboard');

    $response = $this->actingAs($user)->getJson('/json/dashboard')->assertOk();

    $response->assertJsonStructure([
        'orders_fulfilled' => ['today', 'last_7_days', 'last_30_days', 'all_time'],
        'donations_completed' => ['today', 'last_7_days', 'last_30_days', 'all_time'],
        'orders_trend' => ['current', 'prior', 'direction', 'percent'],
        'donations_trend' => ['current', 'prior', 'direction', 'percent'],
        'pipeline' => ['donations', 'orders'],
        'county_breakdown',
        'inventory_summary' => ['item_types_with_stock', 'total_units_on_hand', 'top_categories'],
        'donor_quality' => ['usable', 'outdated', 'trashed', 'diverted', 'loss_rate_percent'],
        'generated_at',
    ]);
});
