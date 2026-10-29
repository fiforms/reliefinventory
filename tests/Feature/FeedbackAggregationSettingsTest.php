<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\FeedbackAggregationSetting;
use App\Models\KioskLocation;
use App\Models\Trust;
use App\Services\Federation\TrustService;

test('show returns the role and the chosen trust, and nothing secret', function () {
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->getJson('/json/feedback-aggregation-settings')
        ->assertOk()
        ->assertJson(['settings' => ['instance_role' => 'remote', 'feedback_trust_id' => null], 'trusts' => []])
        ->assertJsonMissingPath('settings.shared_secret')
        ->assertJsonMissingPath('settings.central_ingest_url');
});

test('update saves the role and the instance to send through', function () {
    $admin = userWithPermissions('manage-feedback');
    $trust = TrustService::enrollInvited(['uuid' => 'c-1', 'name' => 'Central', 'public_key' => str_repeat('A', 43)], 'https://central.example.org', [], ['feedback.send'], null);

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'remote', 'feedback_trust_id' => $trust->id])
        ->assertOk()->assertJsonPath('settings.feedback_trust_id', $trust->id)->assertJsonPath('trusts.0.may_send_feedback', true);
    expect(FeedbackAggregationSetting::current()->pushConfigured())->toBeTrue();

    $this->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'central', 'feedback_trust_id' => null])->assertOk();
    expect(FeedbackAggregationSetting::current()->instance_role)->toBe('central')
        ->and(FeedbackAggregationSetting::current()->pushConfigured())->toBeFalse();
});

test('update refuses a missing role, an unknown trust and a client-app trust', function () {
    $admin = userWithPermissions('manage-feedback');
    $client = fedEnroll(fedPeer('print_client'), [], (KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']))->id);

    $this->actingAs($admin)->putJson('/json/feedback-aggregation-settings', [])->assertStatus(422);
    $this->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'remote', 'feedback_trust_id' => 9999])->assertStatus(422);
    $this->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'remote', 'feedback_trust_id' => $client->id])->assertStatus(422);
    expect(Trust::count())->toBe(1);
});

test('feedback aggregation settings and the connection test require manage-feedback', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/feedback-aggregation-settings')->assertForbidden();
    $this->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'central'])->assertForbidden();
    $this->postJson('/json/feedback-aggregation-settings/test')->assertForbidden();
});

test('the shared-secret endpoints are gone', function () {
    $this->postJson('/json/feedback/ingest', ['instance_identifier' => 'r', 'reports' => []], ['X-Feedback-Shared-Secret' => 'x'])->assertNotFound();
    $this->actingAs(userWithPermissions('manage-feedback'))->postJson('/json/feedback-aggregation-settings/connection-code')->assertStatus(404);
});
