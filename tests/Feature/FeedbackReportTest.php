<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Mail\FeedbackReportStatusUpdated;
use App\Mail\FeedbackReportSubmitted;
use App\Models\FeedbackReport;
use Illuminate\Support\Facades\Mail;

test('a general-access user can submit a feedback report', function () {
    Mail::fake();
    config(['feedback.notify_emails' => ['dev@example.com']]);

    $user = userWithPermissions('general-access');

    $response = $this->actingAs($user)->postJson('/json/feedback-reports', [
        'type' => 'bug',
        'message' => 'The save button does nothing on the Items page.',
        'page_url' => '/items',
        'page_title' => 'Items',
    ]);

    $response->assertCreated();

    $report = FeedbackReport::first();
    expect($report)->not->toBeNull();
    expect($report->person_id)->toBe($user->id);
    expect($report->status)->toBe('new');

    Mail::assertSent(FeedbackReportSubmitted::class);
});

test('submission is rejected without general-access', function () {
    $user = userWithPermissions();

    $this->actingAs($user)->postJson('/json/feedback-reports', [
        'type' => 'bug',
        'message' => 'Test',
        'page_url' => '/items',
    ])->assertForbidden();
});

test('listing and updating reports requires manage-feedback', function () {
    $reporter = userWithPermissions('general-access');
    $report = FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'bug',
        'message' => 'Something broke',
        'page_url' => '/items',
    ]);

    $unauthorized = userWithPermissions('general-access');
    $this->actingAs($unauthorized)->getJson('/json/feedback-reports')->assertForbidden();
    $this->actingAs($unauthorized)->patchJson("/json/feedback-reports/{$report->id}", ['status' => 'seen'])->assertForbidden();

    $admin = userWithPermissions('manage-feedback');
    $this->actingAs($admin)->getJson('/json/feedback-reports')->assertOk()->assertJsonCount(1, 'records');
});

test('resolving a report requires a comment, other transitions do not', function () {
    Mail::fake();
    $reporter = userWithPermissions('general-access');
    $report = FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'feature',
        'message' => 'Add dark mode',
        'page_url' => '/items',
    ]);

    $admin = userWithPermissions('manage-feedback');

    $this->actingAs($admin)->patchJson("/json/feedback-reports/{$report->id}", [
        'status' => 'resolved',
    ])->assertStatus(422);

    $this->actingAs($admin)->patchJson("/json/feedback-reports/{$report->id}", [
        'status' => 'seen',
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/json/feedback-reports/{$report->id}", [
        'status' => 'resolved',
        'comment' => 'Added in the settings menu.',
    ])->assertOk();

    $report->refresh();
    expect($report->status)->toBe('resolved');
    expect($report->statusLogs)->toHaveCount(2);

    Mail::assertSent(FeedbackReportStatusUpdated::class, 2);
});

test('a note can be added without changing status, at any point before resolved', function () {
    Mail::fake();
    $reporter = userWithPermissions('general-access');
    $report = FeedbackReport::create([
        'person_id' => $reporter->id,
        'type' => 'bug',
        'status' => 'new',
        'message' => 'Something broke',
        'page_url' => '/items',
    ]);

    $admin = userWithPermissions('manage-feedback');

    // A note while still "new" (status re-asserted, not advanced).
    $this->actingAs($admin)->patchJson("/json/feedback-reports/{$report->id}", [
        'status' => 'new',
        'comment' => 'Looking into this.',
    ])->assertOk();

    $report->refresh();
    expect($report->status)->toBe('new');
    expect($report->statusLogs)->toHaveCount(1);
    expect($report->statusLogs->first()->status)->toBe('new');

    // Advance for real, then leave another note without advancing further.
    $this->actingAs($admin)->patchJson("/json/feedback-reports/{$report->id}", [
        'status' => 'seen',
    ])->assertOk();
    $this->actingAs($admin)->patchJson("/json/feedback-reports/{$report->id}", [
        'status' => 'seen',
        'comment' => 'Still working on a fix.',
    ])->assertOk();

    $report->refresh();
    expect($report->status)->toBe('seen');
    expect($report->statusLogs)->toHaveCount(3);

    Mail::assertSent(FeedbackReportStatusUpdated::class, 3);
});
