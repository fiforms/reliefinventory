<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\County;
use Illuminate\Support\Facades\Http;

function fakeGeocodioResult(?string $county, ?string $state, ?string $standardizedStreet = '123 Main St'): void
{
    Http::fake([
        'api.geocod.io/*' => Http::response([
            'results' => [
                [
                    'formatted_address' => '123 Main St, Anytown, XX 00000',
                    'accuracy' => 1,
                    'accuracy_type' => 'rooftop',
                    'address_lines' => [$standardizedStreet, '', "Anytown, {$state} 00000"],
                    'address_components' => [
                        'city' => 'Anytown',
                        'state_province' => $state,
                        'postal_code' => '00000',
                        'county' => $county,
                    ],
                ],
            ],
        ], 200),
    ]);
}

test('geocode lookup requires the general-access permission', function () {
    fakeGeocodioResult('Wake County', 'NC');
    config(['services.geocodio.key' => 'test-key']);

    $this->postJson('/json/geocode/county', ['address' => '123 Main St'])->assertStatus(401);
});

test('geocode lookup resolves a matching local county', function () {
    $county = County::create(['county' => 'Wake County', 'state' => 'NC']);
    fakeGeocodioResult('Wake County', 'NC');
    config(['services.geocodio.key' => 'test-key']);
    $actor = userWithPermissions('general-access');

    $response = $this->actingAs($actor)->postJson('/json/geocode/county', [
        'address' => '400 S Salisbury St', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601',
    ]);

    $response->assertOk()->assertJson([
        'county_id' => $county->id,
        'county' => 'Wake County',
        'state' => 'NC',
        'zip' => '00000',
        'address' => '123 Main St',
    ]);
});

test('geocode lookup returns a suggestion without a county_id when no local county matches', function () {
    fakeGeocodioResult('Nowhere County', 'ZZ');
    config(['services.geocodio.key' => 'test-key']);
    $actor = userWithPermissions('general-access');

    $response = $this->actingAs($actor)->postJson('/json/geocode/county', [
        'address' => '1 Nowhere Ln', 'city' => 'Nowhere', 'state' => 'ZZ', 'zip' => '00000',
    ]);

    $response->assertOk()->assertJson([
        'county_id' => null,
        'county' => 'Nowhere County',
        'state' => 'ZZ',
    ]);
});

test('geocode lookup 503s when no API key is configured', function () {
    config(['services.geocodio.key' => null]);
    $actor = userWithPermissions('general-access');

    $this->actingAs($actor)->postJson('/json/geocode/county', ['address' => '123 Main St'])
        ->assertStatus(503);
});

test('geocode lookup 503s when offline mode is on, even with a valid API key', function () {
    config(['services.geocodio.key' => 'test-key']);
    App\Models\OfflineModeSetting::current()->update(['enabled' => true]);
    $actor = userWithPermissions('general-access');

    $this->actingAs($actor)->postJson('/json/geocode/county', ['address' => '123 Main St'])
        ->assertStatus(503);

    App\Models\OfflineModeSetting::current()->update(['enabled' => false]);
});

test('geocode lookup requires an address', function () {
    config(['services.geocodio.key' => 'test-key']);
    $actor = userWithPermissions('general-access');

    $this->actingAs($actor)->postJson('/json/geocode/county', [])
        ->assertStatus(422);
});
