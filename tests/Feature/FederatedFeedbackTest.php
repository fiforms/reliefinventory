<?php

use App\Models\FeedbackAggregationSetting;
use App\Models\FeedbackReport;
use App\Models\KioskLocation;
use App\Models\Trust;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\TrustService;
use App\Services\FeedbackPushService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['federation.allow_private_hosts' => true]);
    InstanceIdentity::current()->update(['base_url' => 'https://b.example.test']);
});

function incomingReport(array $over = []): array
{
    return $over + ['uuid' => (string) Str::uuid(), 'type' => 'bug', 'message' => 'It broke.', 'page_url' => '/items', 'created_at' => '2026-10-01T12:00:00Z'];
}

function localReport(string $message = 'Local report'): FeedbackReport
{
    return FeedbackReport::create(['type' => 'bug', 'message' => $message, 'page_url' => '/x']);
}

/** This instance as a remote that trusts a fake central. $remote is filled in by reference; returns the trust. */
function remoteWithCentral(&$remote, array $over = []): Trust
{
    $remote = fakeRemote(array_merge(['name' => 'Central', 'grants_for_us' => ['feedback.send']], $over));
    serveRemote($remote);
    $trust = TrustService::enrollInvited($remote['doc'], $remote['origin'], [], ['feedback.send'], null);
    FeedbackAggregationSetting::current()->update(['instance_role' => 'remote', 'feedback_trust_id' => $trust->id]);

    return $trust;
}

test('central takes reports from a trusted instance, tags them with the trust, and ignores self-asserted identity', function () {
    $peer = fedPeer('instance');
    $trust = fedEnroll($peer, ['feedback.send']);
    $trust->update(['base_url' => 'https://wa26.example.org']);
    $report = incomingReport();
    $body = json_encode(['instance_identifier' => 'someone-else', 'reports' => [$report, ['uuid' => 'not-a-uuid']]]);

    $res = fedCall($this, $peer, 'POST', '/api/federation/v1/feedback', $body)->assertOk();
    expect($res->json('received'))->toBe([$report['uuid']]);

    $row = FeedbackReport::where('uuid', $report['uuid'])->first();
    expect($row->instance_identifier)->toBe('wa26.example.org')
        ->and($row->trust_id)->toBe($trust->id)
        ->and($row->created_at->toDateString())->toBe('2026-10-01');

    // a retry is idempotent
    fedCall($this, $peer, 'POST', '/api/federation/v1/feedback', json_encode(['reports' => [$report]]))->assertOk();
    expect(FeedbackReport::where('uuid', $report['uuid'])->count())->toBe(1);
});

test('central refuses a trusted peer that was not granted feedback, and an unknown one', function () {
    $peer = fedPeer('instance');
    fedEnroll($peer, ['offers.send']);
    $body = json_encode(['reports' => [incomingReport()]]);

    fedCall($this, $peer, 'POST', '/api/federation/v1/feedback', $body)->assertForbidden();
    fedCall($this, fedPeer('instance'), 'POST', '/api/federation/v1/feedback', $body)->assertUnauthorized();
    expect(FeedbackReport::count())->toBe(0);
});

test('a remote sends unsent reports to its trusted central, signed, and marks only acknowledged ones', function () {
    remoteWithCentral($remote);
    $a = localReport('A');
    $b = localReport('B');

    $result = app(FeedbackPushService::class)->pushUnsent();

    expect($result)->toMatchArray(['attempted' => true, 'total' => 2, 'sent' => 2, 'error' => null])
        ->and(collect($remote['feedback_body']['reports'])->pluck('uuid')->sort()->values()->all())->toBe(collect([$a->uuid, $b->uuid])->sort()->values()->all())
        ->and($a->fresh()->submitted_at)->not->toBeNull();
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/json/feedback/ingest'));
});

test('a remote with a trust never falls back to the shared secret', function () {
    remoteWithCentral($remote, ['feedback_status' => 403]);
    FeedbackAggregationSetting::current()->update(['central_ingest_url' => 'https://old.example.test/json/feedback/ingest', 'shared_secret' => 's3cret']);
    localReport();

    $result = app(FeedbackPushService::class)->pushUnsent();

    expect($result['sent'])->toBe(0)->and($result['error'])->toContain('has not allowed this instance to send feedback');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'old.example.test'));
});

test('a revoked trust turns pushing off and explains why', function () {
    $trust = remoteWithCentral($remote);
    TrustService::revoke($trust, null);
    $service = app(FeedbackPushService::class);

    expect($service->configured())->toBeFalse()
        ->and($service->testConnection()['message'])->toContain('not approved');
});

test('test connection succeeds when central allows feedback', function () {
    remoteWithCentral($remote);
    expect(app(FeedbackPushService::class)->testConnection())->toMatchArray(['ok' => true]);
});

test('test connection says so when central has not granted feedback', function () {
    remoteWithCentral($remote, ['grants_for_us' => []]);
    $result = app(FeedbackPushService::class)->testConnection();
    expect($result['ok'])->toBeFalse()->and($result['message'])->toContain('has not allowed this instance to send feedback');
});

test('the Feedback settings page lists paired instances and saves the chosen one', function () {
    $remote = fakeRemote(['name' => 'Central']);
    serveRemote($remote);
    $trust = TrustService::enrollInvited($remote['doc'], $remote['origin'], [], ['feedback.send'], null);
    $client = fedEnroll(fedPeer('print_client'), [], KioskLocation::firstOrCreate(['name' => 'Site'])->id);
    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->getJson('/json/feedback-aggregation-settings')->assertOk()
        ->assertJsonPath('trusts.0.id', $trust->id)->assertJsonPath('trusts.0.may_send_feedback', true)
        ->assertJsonCount(1, 'trusts')->assertJsonPath('settings.legacy_enabled', true);

    $this->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'remote', 'feedback_trust_id' => $client->id])->assertStatus(422);
    $this->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'remote', 'feedback_trust_id' => $trust->id])
        ->assertOk()->assertJsonPath('settings.feedback_trust_id', $trust->id);
    expect(FeedbackAggregationSetting::current()->pushConfigured())->toBeTrue();

    $this->putJson('/json/feedback-aggregation-settings', ['instance_role' => 'remote', 'feedback_trust_id' => null])->assertOk();
    expect(FeedbackAggregationSetting::current()->feedback_trust_id)->toBeNull();
});

test('the shared-secret ingest and its connection codes can be retired', function () {
    FeedbackAggregationSetting::current()->update(['instance_role' => 'central', 'shared_secret' => 'abc']);
    $payload = ['instance_identifier' => 'x', 'reports' => [incomingReport()]];
    $this->postJson('/json/feedback/ingest', $payload, ['X-Feedback-Shared-Secret' => 'abc'])->assertOk();

    config(['federation.legacy_feedback_secret' => false]);
    $this->postJson('/json/feedback/ingest', $payload, ['X-Feedback-Shared-Secret' => 'abc'])->assertStatus(410);
    $this->actingAs(userWithPermissions('manage-feedback'))
        ->postJson('/json/feedback-aggregation-settings/connection-code')->assertStatus(410);
});
