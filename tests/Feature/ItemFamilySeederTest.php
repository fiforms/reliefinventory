<?php

use App\Models\ItemType;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\ItemFamilySeeder;
use Database\Seeders\PackageTypesSeeder;
use Database\Seeders\UnitsSeeder;

beforeEach(function () {
    $this->seed(UnitsSeeder::class);
    $this->seed(PackageTypesSeeder::class);
    $this->seed(CategoriesSeeder::class);
});

test('seeding assigns every item type a category', function () {
    $this->seed(ItemFamilySeeder::class);

    expect(ItemType::whereNull('category_id')->count())->toBe(0);
});

test('every (family, variant) pair is unique', function () {
    $this->seed(ItemFamilySeeder::class);

    $total = ItemType::count();
    $distinct = ItemType::query()
        ->selectRaw('family, variant')
        ->distinct()
        ->get()
        ->count();

    expect($distinct)->toBe($total);
});

test('re-running the seeder is idempotent', function () {
    $this->seed(ItemFamilySeeder::class);
    $before = ItemType::count();

    $this->seed(ItemFamilySeeder::class);

    expect(ItemType::count())->toBe($before);
});

test('every family is stored as a uniform 4-digit zero-padded string', function () {
    $this->seed(ItemFamilySeeder::class);

    $wrongWidth = ItemType::query()->whereRaw('LENGTH(family) != 4')->count();

    expect($wrongWidth)->toBe(0);
});

test('always-sized families with no natural default have no "00" row at all', function () {
    $this->seed(ItemFamilySeeder::class);

    // 404 is deliberately excluded here: 404-00 is real (newborn diapers),
    // it just isn't "the default item" the way 318-00 is — see the
    // numbering design notes on the one documented -00 exception.
    foreach (['0950', '0510', '0525', '3401', '3411'] as $family) {
        expect(ItemType::where('family', $family)->where('variant', '00')->exists())->toBeFalse();
    }
});

test('the one deliberate exception: 404-00 is a real, specific item (newborn), not a hidden default', function () {
    $this->seed(ItemFamilySeeder::class);

    $newborn = ItemType::where('family', '0404')->where('variant', '00')->first();

    expect($newborn)->not->toBeNull()
        ->and($newborn->name)->toBe('Diapers, newborn')
        ->and($newborn->items()->count())->toBe(1)
        ->and($newborn->display_number)->toBe('404-00');
});

test('retired numbers get no generic item and are inactive', function () {
    $this->seed(ItemFamilySeeder::class);

    $retired = ItemType::where('family', '0488')->first();

    expect($retired->status)->toBe('retired')
        ->and($retired->active)->toBeFalse()
        ->and($retired->items()->count())->toBe(0);
});

test('the standard item of a family displays without its "00" suffix or leading family zero', function () {
    $this->seed(ItemFamilySeeder::class);

    $paperTowels = ItemType::where('family', '0318')->where('variant', '00')->first();

    expect($paperTowels->display_number)->toBe('318');
});

test('a real, non-default variant displays with its suffix', function () {
    $this->seed(ItemFamilySeeder::class);

    $giantRoll = ItemType::where('family', '0318')->where('variant', '90')->first();

    expect($giantRoll->display_number)->toBe('318-90');
});

test('a large-item reserved-block family displays all 4 digits', function () {
    $this->seed(ItemFamilySeeder::class);

    $washer = ItemType::where('family', '1100')->where('variant', '00')->first();

    expect($washer->display_number)->toBe('1100');
});
