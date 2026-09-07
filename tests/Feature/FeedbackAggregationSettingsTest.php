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
