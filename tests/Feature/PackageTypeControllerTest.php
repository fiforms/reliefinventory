<?php

use App\Models\PackageType;

// The packagetypes table has never had created_at/updated_at columns, so
// without PackageType::$timestamps = false, Eloquent's default timestamp
// handling made both of these 500 ("Unknown column 'updated_at'") — the
// underlying cause behind feedback #43 ("package types is blocking adding
// a new item").
test('a package type can be created via the API', function () {
    $user = userWithPermissions('manage-packagetypes');

    $this->actingAs($user)->postJson('/json/packagetypes', [
        'singular' => 'Jug',
        'plural' => 'Jugs',
    ])->assertCreated();

    expect(PackageType::where('singular', 'Jug')->exists())->toBeTrue();
});

test('a package type can be updated via the API', function () {
    $user = userWithPermissions('manage-packagetypes');
    $packageType = PackageType::create(['singular' => 'Crate', 'plural' => 'Crates']);

    $this->actingAs($user)->putJson("/json/packagetypes/{$packageType->id}", [
        'singular' => 'Crate (large)',
        'plural' => 'Crates (large)',
    ])->assertOk();

    expect($packageType->fresh()->singular)->toBe('Crate (large)');
});
