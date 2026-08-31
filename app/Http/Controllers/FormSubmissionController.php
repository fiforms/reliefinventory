<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Review queue for a form's submissions (gated review-form-submissions,
 * separate from manage-forms — see PermissionsSeeder). Approve/deny only
 * apply when the form's requires_approval is true; a plain data-collection
 * form only ever gets markReviewed().
 */
class FormSubmissionController extends Controller
{
    private const WITH = ['answers', 'submittedBy', 'reviewedBy', 'linkedPerson', 'statusLogs.changedBy'];

    public function index(Form $form)
    {
        $submissions = $form->submissions()->with(self::WITH)->orderByDesc('id')->get();

        return response()->json(['records' => $submissions]);
    }

    public function show(Form $form, FormSubmission $submission)
    {
        abort_unless($submission->form_id === $form->id, 404);

        return response()->json(['record' => $submission->load(self::WITH)]);
    }

    public function approve(Request $request, Form $form, FormSubmission $submission)
    {
        abort_unless($submission->form_id === $form->id, 404);

        if (! $form->requires_approval) {
            return response()->json(['message' => 'This form doesn\'t use an approval workflow.'], 422);
        }
        if ($submission->approval_status !== FormSubmission::STATUS_PENDING) {
            return response()->json(['message' => 'Only a pending submission can be approved.'], 422);
        }

        $data = $request->validate([
            'notes' => 'nullable|string',
            // Reviewer's choice when on_approval_action wants a Person:
            // link to an existing record, or create a new one. Ignored for
            // forms whose on_approval_action is 'none'.
            'link_person_id' => 'nullable|integer|exists:people,id',
        ]);

        $columnUpdates = [];
        if ($form->on_approval_action === Form::APPROVAL_CREATE_OR_LINK_PARTNER) {
            $person = $this->resolvePartnerPerson($submission, $data['link_person_id'] ?? null, Auth::id(), $data['notes'] ?? null);
            $columnUpdates['linked_person_id'] = $person->id;
        }

        $submission->transitionTo(FormSubmission::STATUS_APPROVED, Auth::id(), $columnUpdates, $data['notes'] ?? null);

        return response()->json(['record' => $submission->fresh(self::WITH)]);
    }

    public function deny(Request $request, Form $form, FormSubmission $submission)
    {
        abort_unless($submission->form_id === $form->id, 404);

        if ($submission->approval_status !== FormSubmission::STATUS_PENDING) {
            return response()->json(['message' => 'Only a pending submission can be denied.'], 422);
        }

        $data = $request->validate(['notes' => 'nullable|string']);

        $submission->transitionTo(FormSubmission::STATUS_DENIED, Auth::id(), [], $data['notes'] ?? null);

        return response()->json(['record' => $submission->fresh(self::WITH)]);
    }

    /**
     * For a non-approval form (or an already-decided submission on an
     * approval form): acknowledge it's been looked at, optionally with a
     * note — mirrors FeedbackReportController's same-status-note update.
     */
    public function addNote(Request $request, Form $form, FormSubmission $submission)
    {
        abort_unless($submission->form_id === $form->id, 404);

        $data = $request->validate(['notes' => 'nullable|string']);

        $submission->markReviewed(Auth::id(), $data['notes'] ?? null);

        return response()->json(['record' => $submission->fresh(self::WITH)]);
    }

    /**
     * Links to an existing Person if the reviewer picked one, otherwise
     * creates a new org Person from the submission's target_field-mapped
     * answers — either way, idempotently attaches the Partner role, per
     * the party-role-tagging design's explicit-attach convention, and moves
     * partner_status to approved (skipped if already approved — approving
     * this submission for an already-approved partner is a no-op on their
     * status). This targets Person (the only partner-org record the live
     * data model has today) rather than the not-yet-built Facility entity
     * from Part 5.
     */
    private function resolvePartnerPerson(FormSubmission $submission, ?int $linkPersonId, ?int $reviewerId, ?string $notes): Person
    {
        return DB::transaction(function () use ($submission, $linkPersonId, $reviewerId, $notes) {
            if ($linkPersonId) {
                $person = Person::findOrFail($linkPersonId);
            } else {
                $fields = ['is_organization' => true];
                foreach ($submission->answers()->with('question')->get() as $answer) {
                    $target = $answer->question?->target_field;
                    if ($target && in_array($target, Person::firstOrNew()->getFillable(), true)) {
                        $fields[$target] = $answer->displayValue();
                    }
                }

                $person = Person::create($fields);
            }

            if (! $person->hasRole('Partner')) {
                $person->assignRole('Partner');
            }

            if ($person->partner_status !== Person::PARTNER_STATUS_APPROVED) {
                $person->transitionPartnerStatus(Person::PARTNER_STATUS_APPROVED, $reviewerId, $notes, $submission->id);
            }

            return $person;
        });
    }
}
