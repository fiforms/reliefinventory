<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\FeedbackAggregationSetting;

test('show returns settings without exposing the shared secret value', function () {
    FeedbackAggregationSetting::current()->update(['shared_secret' => 'super-secret']);

    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->getJson('/json/feedback-aggregation-settings')
        ->assertOk()
        ->assertJson(['settings' => [
            'instance_role' => 'remote',
            'shared_secret_set' => true,
        ]])
        ->assertJsonMissingPath('settings.shared_secret');
});

test('update saves role, identifier, and ingest url', function () {
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', [
        'instance_role' => 'central',
        'instance_identifier' => 'central.example.org',
        'central_ingest_url' => null,
    ])->assertOk()->assertJson(['settings' => [
        'instance_role' => 'central',
        'instance_identifier' => 'central.example.org',
        'shared_secret_set' => false,
    ]]);

    expect(FeedbackAggregationSetting::current()->instance_role)->toBe('central');
});

test('a blank shared secret on update leaves the existing one unchanged', function () {
    FeedbackAggregationSetting::current()->update(['shared_secret' => 'keep-me']);
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', [
        'instance_role' => 'remote',
        'central_ingest_url' => 'https://central.example.org/json/feedback/ingest',
    ])->assertOk();

    expect(FeedbackAggregationSetting::current()->shared_secret)->toBe('keep-me');
});

test('a non-blank shared secret on update replaces the existing one', function () {
    FeedbackAggregationSetting::current()->update(['shared_secret' => 'old-secret']);
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', [
        'instance_role' => 'remote',
        'shared_secret' => 'new-secret',
    ])->assertOk();

    expect(FeedbackAggregationSetting::current()->shared_secret)->toBe('new-secret');
});

test('feedback aggregation settings require manage-feedback', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/feedback-aggregation-settings')->assertForbidden();
    $this->actingAs($user)->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'remote'])->assertForbidden();
});

test('the central address is normalized from a bare domain or site address', function (string $typed, ?string $expected) {
    expect(FeedbackAggregationSetting::normalizeCentralUrl($typed))->toBe($expected);
})->with([
    ['demo.reliefinventory.app', 'https://demo.reliefinventory.app/json/feedback/ingest'],
    ['  https://Demo.ReliefInventory.app/ ', 'https://demo.reliefinventory.app/json/feedback/ingest'],
    ['https://demo.reliefinventory.app/setup/feedback?x=1', 'https://demo.reliefinventory.app/json/feedback/ingest'],
    ['https://demo.reliefinventory.app/json/feedback/ingest', 'https://demo.reliefinventory.app/json/feedback/ingest'],
    ['http://localhost:8080', 'http://localhost:8080/json/feedback/ingest'],
    ['ftp://demo.reliefinventory.app', null],
    ['', null],
    ['not a url at all', null],
]);

test('update saves a bare domain as the full ingest url', function () {
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', [
        'instance_role' => 'remote',
        'central_ingest_url' => 'demo.reliefinventory.app',
    ])->assertOk()->assertJsonPath('settings.central_ingest_url', 'https://demo.reliefinventory.app/json/feedback/ingest');

    expect(FeedbackAggregationSetting::current()->central_ingest_url)->toBe('https://demo.reliefinventory.app/json/feedback/ingest');
});

test('update refuses an address that cannot be used', function () {
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', [
        'instance_role' => 'remote',
        'central_ingest_url' => 'not a url at all',
    ])->assertStatus(422)->assertJsonValidationErrors('central_ingest_url');
});

test('a stored bare site address still pushes to the ingest path', function () {
    FeedbackAggregationSetting::current()->update([
        'instance_role' => 'remote',
        'central_ingest_url' => 'https://demo.reliefinventory.app',
        'shared_secret' => 's',
    ]);

    expect(FeedbackAggregationSetting::current()->ingestUrl())->toBe('https://demo.reliefinventory.app/json/feedback/ingest')
        ->and(FeedbackAggregationSetting::current()->pushConfigured())->toBeTrue();
});

test('central makes a connection code holding its ingest url and a new secret', function () {
    config(['app.url' => 'https://central.example.org']);
    FeedbackAggregationSetting::current()->update(['instance_role' => 'central', 'shared_secret' => null]);
    $admin = userWithPermissions('manage-feedback');

    $code = $this->actingAs($admin)->postJson('/json/feedback-aggregation-settings/connection-code')
        ->assertOk()->assertJsonPath('settings.shared_secret_set', true)->json('code');

    $secret = FeedbackAggregationSetting::current()->shared_secret;
    expect($secret)->toHaveLength(40)
        ->and(FeedbackAggregationSetting::parseConnectionCode($code))
        ->toBe(['url' => 'https://central.example.org/json/feedback/ingest', 'secret' => $secret]);

    // Asking again keeps the same secret; new_secret replaces it.
    $this->actingAs($admin)->postJson('/json/feedback-aggregation-settings/connection-code')->assertJsonPath('code', $code);
    $this->actingAs($admin)->postJson('/json/feedback-aggregation-settings/connection-code', ['new_secret' => true]);
    expect(FeedbackAggregationSetting::current()->shared_secret)->not->toBe($secret);
});

test('only a central instance has a connection code', function () {
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->postJson('/json/feedback-aggregation-settings/connection-code')->assertStatus(409);
    expect(FeedbackAggregationSetting::current()->shared_secret)->toBeNull();
});

test('pasting a connection code sets this instance up as a remote', function () {
    $admin = userWithPermissions('manage-feedback');
    FeedbackAggregationSetting::current()->update(['instance_role' => 'central']);
    $code = FeedbackAggregationSetting::connectionCode('https://central.example.org/json/feedback/ingest', 'pasted-secret');

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', ['connection_code' => "  $code\n"])
        ->assertOk()
        ->assertJsonPath('settings.instance_role', 'remote')
        ->assertJsonPath('settings.central_ingest_url', 'https://central.example.org/json/feedback/ingest')
        ->assertJsonPath('settings.shared_secret_set', true);

    expect(FeedbackAggregationSetting::current()->shared_secret)->toBe('pasted-secret');
});

test('a garbled connection code is refused and changes nothing', function () {
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', ['connection_code' => 'RIFEED1-garbage'])
        ->assertStatus(422)->assertJsonValidationErrors('connection_code');
    expect(FeedbackAggregationSetting::current()->central_ingest_url)->toBeNull();
});

test('the new connection-code and test endpoints require manage-feedback', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->postJson('/json/feedback-aggregation-settings/connection-code')->assertForbidden();
    $this->actingAs($user)->postJson('/json/feedback-aggregation-settings/test')->assertForbidden();
});
