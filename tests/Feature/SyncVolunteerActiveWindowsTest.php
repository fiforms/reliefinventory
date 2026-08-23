<?php

use App\Models\Person;

test('a scheduled volunteer activates on their window start date', function () {
    $volunteer = Person::create([
        'first_name' => 'Val', 'last_name' => 'Unteer', 'is_volunteer' => true,
        'volunteer_active' => false,
        'volunteer_window_start' => now()->toDateString(),
        'volunteer_window_end' => now()->addWeeks(3)->toDateString(),
    ]);

    $this->artisan('volunteers:sync-active-windows')->assertSuccessful();

    expect($volunteer->fresh()->volunteer_active)->toBeTrue();
});

test('a scheduled volunteer stays active through their last day and deactivates the day after', function () {
    $stillOnLastDay = Person::create([
        'first_name' => 'Lex', 'last_name' => 'LastDay', 'is_volunteer' => true,
        'volunteer_active' => true,
        'volunteer_window_start' => now()->subWeeks(3)->toDateString(),
        'volunteer_window_end' => now()->toDateString(),
    ]);
    $endedYesterday = Person::create([
        'first_name' => 'Del', 'last_name' => 'Done', 'is_volunteer' => true,
        'volunteer_active' => true,
        'volunteer_window_start' => now()->subWeeks(4)->toDateString(),
        'volunteer_window_end' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('volunteers:sync-active-windows')->assertSuccessful();

    expect($stillOnLastDay->fresh()->volunteer_active)->toBeTrue()
        ->and($endedYesterday->fresh()->volunteer_active)->toBeFalse();
});

test('a manual admin toggle in the middle of a window is left alone', function () {
    $manuallyDeactivatedMidWindow = Person::create([
        'first_name' => 'Mid', 'last_name' => 'Window', 'is_volunteer' => true,
        'volunteer_active' => false, // admin turned them off early, mid-window
        'volunteer_window_start' => now()->subWeek()->toDateString(),
        'volunteer_window_end' => now()->addWeek()->toDateString(),
    ]);

    $this->artisan('volunteers:sync-active-windows')->assertSuccessful();

    expect($manuallyDeactivatedMidWindow->fresh()->volunteer_active)->toBeFalse();
});
