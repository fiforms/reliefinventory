<?php

use App\Models\MenuItem;

test('the breadcrumb still finds a menu item whose link_url carries a query string', function () {
    // Regression guard: MenuItem::getBreadcrumb() previously matched
    // link_url exactly, so a menu item whose link_url carried a query
    // string couldn't be found from the plain path a route passes in, and
    // the page 500'd trying to read a property off a null result. The Setup
    // tile itself no longer carries one (enable-confirmation moved to
    // Dashboard.vue, see KioskEnableConfirmModal), but the lookup should
    // stay tolerant of one regardless.
    $menuItem = MenuItem::where('link_url', '/volunteers/kiosk')->firstOrFail();
    $menuItem->update(['link_url' => '/volunteers/kiosk?foo=1']);

    $breadcrumb = MenuItem::getBreadcrumb('/volunteers/kiosk');

    expect(collect($breadcrumb)->last())->toBe(['href' => '/volunteers/kiosk', 'title' => 'Sign-in Kiosk']);
});

test('a page with no menu entry still gets a breadcrumb instead of crashing', function () {
    expect(MenuItem::getBreadcrumb('/pallet-management'))->toBe([['href' => '/pallet-management', 'title' => 'Pallet Management']]);
});
