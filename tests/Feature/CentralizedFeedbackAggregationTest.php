<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\FeedbackReport;
use App\Models\OfflineModeSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const CENTRAL_URL = 'https://central.example.test/json/feedback/ingest';

function configureAsRemote(): void
{
    config([
        'feedback.instance_role' => 'remote',
        'feedback.central_ingest_url' => CENTRAL_URL,
        'feedback.shared_secret' => 'test-shared-secret',
    ]);
}

test('central ingest upserts idempotently on (instance_identifier, uuid)', function () {
    config(['feedback.shared_secret' => 'test-shared-secret']);

    $uuid = (string) Str::uuid();
    $payload = [
        'instance_identifier' => 'wa26.reliefinventory.app',
        'reports' => [[
            'uuid' => $uuid,
            'type' => 'bug',
            'message' => 'The save button does nothing on the Items page.',
            'page_url' => '/items',
            'reporter_name' => 'Jane Doe',
            'reporter_email' => 'jane@example.com',
        ]],
    ];

    $this->postJson('/json/feedback/ingest', $payload, ['X-Feedback-Shared-Secret' => 'test-shared-secret'])
        ->assertOk()
        ->assertJson(['received' => [$uuid]]);

    // A retried push (e.g. the sender never saw the first ack) must not
    // create a second row.
    $this->postJson('/json/feedback/ingest', $payload, ['X-Feedback-Shared-Secret' => 'test-shared-secret'])
        ->assertOk()
        ->assertJson(['received' => [$uuid]]);

    expect(FeedbackReport::where('uuid', $uuid)->count())->toBe(1);

    $report = FeedbackReport::where('uuid', $uuid)->first();
    expect($report->instance_identifier)->toBe('wa26.reliefinventory.app');
    expect($report->reporter_name)->toBe('Jane Doe');
    expect($report->reporter_email)->toBe('jane@example.com');
    expect($report->person_id)->toBeNull();
    expect($report->status)->toBe('new');
});

test('central ingest is rejected without the correct shared secret', function () {
    config(['feedback.shared_secret' => 'test-shared-secret']);

    $payload = ['instance_identifier' => 'wa26.reliefinventory.app', 'reports' => [[
        'uuid' => (string) Str::uuid(), 'type' => 'bug', 'message' => 'x', 'page_url' => '/x',
    ]]];

    $this->postJson('/json/feedback/ingest', $payload)->assertForbidden();
    $this->postJson('/json/feedback/ingest', $payload, ['X-Feedback-Shared-Secret' => 'wrong'])->assertForbidden();

    expect(FeedbackReport::count())->toBe(0);
});

test('central ingest skips a malformed report but still stores the valid ones in the same batch', function () {
    config(['feedback.shared_secret' => 'test-shared-secret']);

    $goodUuid = (string) Str::uuid();
    $payload = [
        'instance_identifier' => 'wa26.reliefinventory.app',
        'reports' => [
            ['uuid' => $goodUuid, 'type' => 'bug', 'message' => 'Real one.', 'page_url' => '/items'],
            ['uuid' => (string) Str::uuid(), 'type' => 'not-a-real-type', 'message' => 'Bad type.', 'page_url' => '/items'],
        ],
    ];

    $this->postJson('/json/feedback/ingest', $payload, ['X-Feedback-Shared-Secret' => 'test-shared-secret'])
        ->assertOk()
        ->assertJson(['received' => [$goodUuid]]);

    expect(FeedbackReport::count())->toBe(1);
});

test('offline mode suppresses automatic push to central', function () {
    Http::fake();
    configureAsRemote();
    OfflineModeSetting::current()->update(['enabled' => true]);

    $user = userWithPermissions('general-access');
    $this->actingAs($user)->postJson('/json/feedback-reports', [
        'type' => 'bug',
        'message' => 'Test report.',
        'page_url' => '/items',
    ])->assertCreated();

    Http::assertNothingSent();
    expect(FeedbackReport::first()->submitted_at)->toBeNull();
});

test('a new report is auto-pushed to central and marked submitted only on a real ack', function () {
    configureAsRemote();

    Http::fake(function ($request) {
        $uuid = $request->data()['reports'][0]['uuid'];

        return Http::response(['received' => [$uuid]], 200);
    });

    $user = userWithPermissions('general-access');
    $this->actingAs($user)->postJson('/json/feedback-reports', [
        'type' => 'bug',
        'message' => 'Test report.',
        'page_url' => '/items',
    ])->assertCreated();

    $report = FeedbackReport::first();
    expect($report->submitted_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === CENTRAL_URL
        && $request->hasHeader('X-Feedback-Shared-Secret', 'test-shared-secret'));
});

test('a create-time push that gets no ack leaves the report unsubmitted, without failing the request', function () {
    configureAsRemote();

    Http::fake([CENTRAL_URL => Http::response('', 500)]);

    $user = userWithPermissions('general-access');
    $this->actingAs($user)->postJson('/json/feedback-reports', [
        'type' => 'bug',
        'message' => 'Test report.',
        'page_url' => '/items',
    ])->assertCreated();

    expect(FeedbackReport::first()->submitted_at)->toBeNull();
});

test('send-unsent sends only unsubmitted reports and marks submitted only on a real ack', function () {
    configureAsRemote();

    $reporter = userWithPermissions('general-access');

    $alreadySubmitted = FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'bug',
        'message' => 'Old, already sent.',
        'page_url' => '/x',
        'submitted_at' => now()->subDay(),
    ]);
    $unsentA = FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'bug',
        'message' => 'New A.',
        'page_url' => '/a',
    ]);
    $unsentB = FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'bug',
        'message' => 'New B.',
        'page_url' => '/b',
    ]);

    // Central only acknowledges one of the two unsent reports — a
    // partial-batch-ack (e.g. the other failed validation on arrival).
    Http::fake([
        CENTRAL_URL => Http::response(['received' => [$unsentA->uuid]], 200),
    ]);

    $admin = userWithPermissions('manage-feedback');
    $this->actingAs($admin)->postJson('/json/feedback-reports/send-unsent')
        ->assertOk()
        ->assertJson(['attempted' => true, 'total' => 2, 'sent' => 1]);

    Http::assertSent(function ($request) use ($unsentA, $unsentB, $alreadySubmitted) {
        $uuids = collect($request->data()['reports'])->pluck('uuid');

        return $uuids->contains($unsentA->uuid)
            && $uuids->contains($unsentB->uuid)
            && ! $uuids->contains($alreadySubmitted->uuid);
    });

    expect($unsentA->fresh()->submitted_at)->not->toBeNull();
    expect($unsentB->fresh()->submitted_at)->toBeNull();
});

test('send-unsent bypasses offline mode, since it is the explicit connectivity override', function () {
    configureAsRemote();
    OfflineModeSetting::current()->update(['enabled' => true]);

    $reporter = userWithPermissions('general-access');
    $report = FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'bug',
        'message' => 'Needs to go out.',
        'page_url' => '/a',
    ]);

    Http::fake([CENTRAL_URL => Http::response(['received' => [$report->uuid]], 200)]);

    $admin = userWithPermissions('manage-feedback');
    $this->actingAs($admin)->postJson('/json/feedback-reports/send-unsent')
        ->assertOk()
        ->assertJson(['attempted' => true, 'total' => 1, 'sent' => 1]);

    Http::assertSent(fn ($request) => $request->url() === CENTRAL_URL);
    expect($report->fresh()->submitted_at)->not->toBeNull();
});

test('send-unsent is a no-op, not an error, when central push is not configured', function () {
    // Default config: instance_role=remote but no central_ingest_url/shared_secret set.
    Http::fake();

    $reporter = userWithPermissions('general-access');
    FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'bug',
        'message' => 'Nowhere to send this.',
        'page_url' => '/a',
    ]);

    $admin = userWithPermissions('manage-feedback');
    $this->actingAs($admin)->postJson('/json/feedback-reports/send-unsent')
        ->assertOk()
        ->assertJson(['attempted' => false, 'total' => 0, 'sent' => 0]);

    Http::assertNothingSent();
});

test('send-unsent requires manage-feedback', function () {
    $user = userWithPermissions('general-access');
    $this->actingAs($user)->postJson('/json/feedback-reports/send-unsent')->assertForbidden();
});
