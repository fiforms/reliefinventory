<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Mail\FormSubmissionNotification;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\User;
use App\Notifications\FormSubmissionReceived;
use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The public/staff-facing side of a Form — /forms/{slug}, no 'auth'
 * middleware. Access is gated per-form by access_mode instead of a route
 * middleware, since the same route serves both an anonymous prospective
 * partner and a logged-in staffer filling it out on someone's behalf.
 * Unauthenticated submissions require a Turnstile pass, same pattern as
 * Register/ForgotPassword; a logged-in submitter is already trusted and
 * skips it.
 */
class PublicFormController extends Controller
{
    public function __construct(private TurnstileService $turnstile) {}

    public function show(string $slug)
    {
        $form = Form::where('slug', $slug)->where('status', Form::STATUS_ACTIVE)->with('questions')->firstOrFail();

        $this->assertAccessAllowed($form);

        $turnstileEnabled = $this->turnstileRequired();
        // Enabled with no keys: say so on the page instead of rendering an
        // empty widget that can never produce the token submit() demands.
        $turnstileUnavailable = $turnstileEnabled && $this->turnstile->misconfigured();

        return Inertia::render('PublicForm', [
            'form' => $form,
            'turnstile_enabled' => $turnstileEnabled && ! $turnstileUnavailable,
            'turnstile_site_key' => $turnstileEnabled && ! $turnstileUnavailable ? $this->turnstile->siteKey() : null,
            'turnstile_unavailable' => $turnstileUnavailable,
        ]);
    }

    public function submit(Request $request, string $slug)
    {
        $form = Form::where('slug', $slug)->where('status', Form::STATUS_ACTIVE)->with('questions')->firstOrFail();

        $this->assertAccessAllowed($form);

        $turnstileEnabled = $this->turnstileRequired();
        if ($turnstileEnabled && $this->turnstile->misconfigured()) {
            return response()->json([
                'message' => 'This form can\'t accept submissions right now because its spam protection isn\'t set up on this server. Please contact the organization that sent you this link.',
            ], 503);
        }

        $rules = [
            'answers' => 'required|array',
            'submitter_name' => Auth::check() ? 'nullable|string|max:255' : 'required|string|max:255',
            'submitter_email' => 'nullable|email|max:255',
            'submitter_phone' => 'nullable|string|max:50',
            'cf-turnstile-response' => $turnstileEnabled ? 'required' : 'nullable',
        ];
        $data = $request->validate($rules);

        if ($turnstileEnabled) {
            $this->turnstile->verify($data['cf-turnstile-response'], $request->ip());
        }

        $questionsById = $form->questions->keyBy('id');
        foreach ($form->questions->where('required', true) as $question) {
            if (! $question->isAnswerable()) {
                continue;
            }
            $answer = $data['answers'][$question->id] ?? null;
            if ($answer === null || $answer === '' || $answer === []) {
                throw ValidationException::withMessages([
                    "answers.{$question->id}" => ["{$question->label} is required."],
                ]);
            }
        }

        $submission = DB::transaction(function () use ($form, $data, $questionsById, $request) {
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'approval_status' => $form->requires_approval ? FormSubmission::STATUS_PENDING : null,
                'submitted_by_person_id' => Auth::id(),
                'submitter_name' => $data['submitter_name'] ?? Auth::user()?->full_name,
                'submitter_email' => $data['submitter_email'] ?? Auth::user()?->email,
                'submitter_phone' => $data['submitter_phone'] ?? null,
                'ip_address' => $request->ip(),
            ]);

            foreach ($data['answers'] as $questionId => $value) {
                /** @var FormQuestion|null $question */
                $question = $questionsById->get((int) $questionId);
                if (! $question || ! $question->isAnswerable()) {
                    continue;
                }
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                FormAnswer::create([
                    'form_submission_id' => $submission->id,
                    'form_question_id' => $question->id,
                    'question_label_snapshot' => $question->label,
                    'question_type_snapshot' => $question->type,
                    'value_json' => in_array($question->type, FormQuestion::CHOICE_TYPES, true) && is_array($value) ? $value : null,
                    'value_text' => is_array($value) ? null : (string) $value,
                ]);
            }

            return $submission;
        });

        $this->notify($form, $submission);

        return response()->json(['message' => 'Thank you — your submission has been received.']);
    }

    private function assertAccessAllowed(Form $form): void
    {
        $allowed = Auth::check() ? $form->allowsStaffAccess() : $form->allowsPublicAccess();

        abort_unless($allowed, Auth::check() ? 403 : 401, Auth::check()
            ? 'This form is not available.'
            : 'Please log in to access this form.');
    }

    private function turnstileRequired(): bool
    {
        return ! Auth::check() && $this->turnstile->enabled();
    }

    private function notify(Form $form, FormSubmission $submission): void
    {
        if (! empty($form->notify_person_ids)) {
            $recipients = User::whereIn('id', $form->notify_person_ids)->get();
            Notification::send($recipients, new FormSubmissionReceived($submission));
        }

        foreach (array_filter(array_map('trim', explode(',', (string) $form->notify_emails))) as $email) {
            Mail::to($email)->queue(new FormSubmissionNotification($submission));
        }
    }
}
