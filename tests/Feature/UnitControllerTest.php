<?php

use App\Models\Unit;

// UnitController's unique rules checked the whole units table on every save,
// including update — so re-saving an existing unit without changing its name
// or abbreviation collided with itself ("name has already been taken"),
// found while reviewing the new /setup/units admin page.
test('an existing unit can be saved again without changing its name or abbreviation', function () {
    $user = userWithPermissions('manage-units');
    $unit = Unit::create(['abbreviation' => 'qz', 'name' => 'Quiz-unit', 'type' => 'each']);

    $this->actingAs($user)->putJson("/json/units/{$unit->id}", [
        'abbreviation' => 'qz',
        'name' => 'Quiz-unit',
        'type' => 'each',
    ])->assertOk();
});

test('a unit cannot be renamed to collide with a different unit', function () {
    $user = userWithPermissions('manage-units');
    Unit::create(['abbreviation' => 'qz', 'name' => 'Quiz-unit', 'type' => 'each']);
    $other = Unit::create(['abbreviation' => 'qy', 'name' => 'Quiy-unit', 'type' => 'each']);

    $this->actingAs($user)->putJson("/json/units/{$other->id}", [
        'abbreviation' => 'qy',
        'name' => 'Quiz-unit',
        'type' => 'each',
    ])->assertStatus(422);
});
