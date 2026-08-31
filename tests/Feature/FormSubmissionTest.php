<?php

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Person;

function makeForm(array $overrides = []): Form
{
    return Form::create(array_merge([
        'name' => 'Test Form',
        'slug' => 'test-form-'.uniqid(),
        'status' => Form::STATUS_ACTIVE,
        'access_mode' => Form::ACCESS_BOTH,
        'requires_approval' => false,
        'on_approval_action' => Form::APPROVAL_NONE,
    ], $overrides));
}

test('an unauthenticated visitor can submit a public form and its answers are recorded', function () {
    $form = makeForm();
    $question = $form->questions()->create([
        'order' => 0,
        'label' => 'Organization Name',
        'type' => 'short_text',
        'required' => true,
        'target_field' => 'organization',
    ]);

    $this->postJson("/forms/{$form->slug}", [
        'answers' => [$question->id => 'Acme Relief'],
        'submitter_name' => 'Jane Doe',
        'submitter_email' => 'jane@example.org',
    ])->assertOk();

    $submission = FormSubmission::where('form_id', $form->id)->firstOrFail();
    expect($submission->submitter_name)->toBe('Jane Doe')
        ->and($submission->approval_status)->toBeNull()
        ->and($submission->answers)->toHaveCount(1)
        ->and($submission->answers->first()->value_text)->toBe('Acme Relief');
});

test('submitting is rejected when a required question is left blank', function () {
    $form = makeForm();
    $question = $form->questions()->create([
        'order' => 0,
        'label' => 'Organization Name',
        'type' => 'short_text',
        'required' => true,
    ]);

    $this->postJson("/forms/{$form->slug}", [
        'answers' => [$question->id => ''],
        'submitter_name' => 'Jane Doe',
    ])->assertStatus(422);
});

test('a staff_only form rejects an unauthenticated visitor', function () {
    $form = makeForm(['access_mode' => Form::ACCESS_STAFF_ONLY]);

    $this->getJson("/forms/{$form->slug}")->assertStatus(401);
});

test('approving a submission on a create_or_link_partner form creates a Partner-tagged person', function () {
    $form = makeForm([
        'requires_approval' => true,
        'on_approval_action' => Form::APPROVAL_CREATE_OR_LINK_PARTNER,
    ]);
    $question = $form->questions()->create([
        'order' => 0,
        'label' => 'Organization Name',
        'type' => 'short_text',
        'required' => true,
        'target_field' => 'organization',
    ]);

    $this->postJson("/forms/{$form->slug}", [
        'answers' => [$question->id => 'Acme Relief'],
        'submitter_name' => 'Jane Doe',
    ])->assertOk();

    $submission = FormSubmission::where('form_id', $form->id)->firstOrFail();
    expect($submission->approval_status)->toBe(FormSubmission::STATUS_PENDING);

    $reviewer = userWithPermissions('review-form-submissions');
    $this->actingAs($reviewer)
        ->postJson("/json/forms/{$form->id}/submissions/{$submission->id}/approve", [])
        ->assertOk();

    $submission->refresh();
    expect($submission->approval_status)->toBe(FormSubmission::STATUS_APPROVED)
        ->and($submission->linked_person_id)->not->toBeNull();

    $person = Person::findOrFail($submission->linked_person_id);
    expect($person->organization)->toBe('Acme Relief')
        ->and($person->is_organization)->toBeTrue()
        ->and($person->hasRole('Partner'))->toBeTrue()
        ->and($person->partner_status)->toBe(Person::PARTNER_STATUS_APPROVED)
        ->and($person->partnerStatusLogs)->toHaveCount(1)
        ->and($person->partnerStatusLogs->first()->form_submission_id)->toBe($submission->id);
});

test('the form builder routes require the manage-forms permission', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/forms')->assertStatus(403);
});

test('the submission review routes require the review-form-submissions permission', function () {
    $form = makeForm();
    $user = userWithPermissions('manage-forms');

    $this->actingAs($user)->getJson("/json/forms/{$form->id}/submissions")->assertStatus(403);
});
