<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\FeedbackReport;
use App\Models\OfflineModeSetting;
use App\Services\Federation\InstanceIdentity;
use Illuminate\Support\Facades\Http;

// The remote side of centralized feedback aggregation: when reports are pushed, what counts as "sent", and what an
// admin is told when nothing went. Central's side (trust-auth ingest) is in FederatedFeedbackTest.

beforeEach(function () {
    config(['federation.allow_private_hosts' => true]);
    InstanceIdentity::current()->update(['base_url' => 'https://b.example.test']);
});

function postFeedbackReport($test): void
{
    $test->actingAs(userWithPermissions('general-access'))->postJson('/json/feedback-reports', [
        'type' => 'bug',
        'message' => 'Test report.',
        'page_url' => '/items',
    ])->assertCreated();
}

test('offline mode suppresses automatic push to central', function () {
    remoteWithCentral($remote);
    OfflineModeSetting::current()->update(['enabled' => true]);

    postFeedbackReport($this);

    Http::assertNothingSent();
    expect(FeedbackReport::first()->submitted_at)->toBeNull();
});

test('a new report is auto-pushed to central and marked submitted only on a real ack', function () {
    remoteWithCentral($remote);

    postFeedbackReport($this);

    $report = FeedbackReport::first();
    expect($report->submitted_at)->not->toBeNull()
        ->and($remote['feedback_body']['reports'][0]['uuid'])->toBe($report->uuid);
});

test('a create-time push that gets no ack leaves the report unsubmitted, without failing the request', function () {
    remoteWithCentral($remote, ['feedback_status' => 500]);

    postFeedbackReport($this);

    expect(FeedbackReport::first()->submitted_at)->toBeNull();
});

test('send-unsent sends only unsubmitted reports and marks submitted only on a real ack', function () {
    remoteWithCentral($remote);
    $alreadySubmitted = localReport('Old, already sent.', ['submitted_at' => now()->subDay()]);
    $unsentA = localReport('New A.');
    $unsentB = localReport('New B.');
    // Central only acknowledges one of the two unsent reports — a partial-batch-ack (e.g. the other failed validation on arrival).
    $remote['feedback_ack_only'] = [$unsentA->uuid];

    $this->actingAs(userWithPermissions('manage-feedback'))->postJson('/json/feedback-reports/send-unsent')
        ->assertOk()
        ->assertJson(['attempted' => true, 'total' => 2, 'sent' => 1]);

    $sent = collect($remote['feedback_body']['reports'])->pluck('uuid');
    expect($sent->all())->toContain($unsentA->uuid, $unsentB->uuid)->not->toContain($alreadySubmitted->uuid)
        ->and($unsentA->fresh()->submitted_at)->not->toBeNull()
        ->and($unsentB->fresh()->submitted_at)->toBeNull();
});

test('send-unsent bypasses offline mode, since it is the explicit connectivity override', function () {
    remoteWithCentral($remote);
    OfflineModeSetting::current()->update(['enabled' => true]);
    $report = localReport('Needs to go out.');

    $this->actingAs(userWithPermissions('manage-feedback'))->postJson('/json/feedback-reports/send-unsent')
        ->assertOk()
        ->assertJson(['attempted' => true, 'total' => 1, 'sent' => 1]);

    expect($report->fresh()->submitted_at)->not->toBeNull();
});

test('send-unsent is a no-op, not an error, when no central instance has been chosen', function () {
    Http::fake();
    localReport('Nowhere to send this.');

    $this->actingAs(userWithPermissions('manage-feedback'))->postJson('/json/feedback-reports/send-unsent')
        ->assertOk()
        ->assertJson(['attempted' => false, 'total' => 0, 'sent' => 0]);

    Http::assertNothingSent();
});

test('send-unsent requires manage-feedback', function () {
    $this->actingAs(userWithPermissions('general-access'))->postJson('/json/feedback-reports/send-unsent')->assertForbidden();
});

test('send-unsent tells the admin why nothing was sent', function (int $status, string $expected) {
    remoteWithCentral($remote, ['feedback_status' => $status]);
    localReport('m');

    $this->actingAs(userWithPermissions('manage-feedback'))->postJson('/json/feedback-reports/send-unsent')
        ->assertOk()->assertJsonPath('sent', 0)->assertJsonPath('total', 1)
        ->assertJson(fn ($json) => $json->where('error', fn ($e) => str_contains($e, $expected))->etc());
})->with([
    'not granted' => [403, 'has not allowed this instance to send feedback'],
    'no longer trusted' => [401, 'no longer trusts this instance'],
    'clock off' => [400, 'clock is too far off'],
    'maintenance' => [503, 'down for maintenance'],
]);
