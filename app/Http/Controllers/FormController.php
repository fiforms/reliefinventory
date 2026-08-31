<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormQuestion;
use App\Models\FormQuestionPreset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin builder for reusable form/survey definitions (gated manage-forms).
 * Submission review lives in FormSubmissionController, gated separately
 * (review-form-submissions) — see the form-builder-and-partner-intake
 * design memory.
 */
class FormController extends Controller
{
    public function index()
    {
        $forms = Form::with('questions')->withCount('submissions')->orderByDesc('id')->get();

        return response()->json([
            'records' => $forms,
            'templates' => [
                '_default' => [
                    'name' => null,
                    'slug' => null,
                    'intro_text' => null,
                    'status' => Form::STATUS_DRAFT,
                    'access_mode' => Form::ACCESS_STAFF_ONLY,
                    'requires_approval' => false,
                    'on_approval_action' => Form::APPROVAL_NONE,
                    'notify_person_ids' => [],
                    'notify_emails' => null,
                ],
            ],
        ]);
    }

    public function show(Form $form)
    {
        $form->load('questions');

        return response()->json(['record' => $form]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $data['created_by_person_id'] = Auth::id();

        $form = Form::create($data);

        return response()->json(['record' => $form->load('questions')], 201);
    }

    public function update(Request $request, Form $form)
    {
        $data = $this->validated($request, $form->id);
        if (! empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $form->id);
        }

        $form->update($data);

        return response()->json(['record' => $form->load('questions')]);
    }

    public function destroy(Form $form)
    {
        if ($form->submissions()->exists()) {
            return response()->json(['message' => 'Can\'t delete a form that already has submissions.'], 422);
        }

        $form->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('forms', 'slug')->ignore($ignoreId),
            ],
            'intro_text' => 'nullable|string',
            'status' => ['required', Rule::in([Form::STATUS_DRAFT, Form::STATUS_ACTIVE, Form::STATUS_ARCHIVED])],
            'access_mode' => ['required', Rule::in([Form::ACCESS_PUBLIC, Form::ACCESS_STAFF_ONLY, Form::ACCESS_BOTH])],
            'requires_approval' => 'nullable|boolean',
            'on_approval_action' => ['nullable', Rule::in([Form::APPROVAL_NONE, Form::APPROVAL_CREATE_OR_LINK_PARTNER])],
            'notify_person_ids' => 'nullable|array',
            'notify_person_ids.*' => 'integer|exists:people,id',
            'notify_emails' => 'nullable|string|max:1000',
        ]);
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source);
        $slug = $base;
        $i = 2;
        while (Form::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function presets()
    {
        return response()->json(['records' => FormQuestionPreset::orderBy('order')->get()]);
    }

    public function addPresets(Request $request, Form $form)
    {
        $data = $request->validate([
            'keys' => 'required|array',
            'keys.*' => 'string',
        ]);

        $presets = FormQuestionPreset::whereIn('key', $data['keys'])->orderBy('order')->get();
        $existingKeys = $form->questions()->whereIn('preset_key', $data['keys'])->pluck('preset_key')->all();
        $nextOrder = (int) ($form->questions()->max('order') ?? -1) + 1;

        DB::transaction(function () use ($presets, $existingKeys, $form, &$nextOrder) {
            foreach ($presets as $preset) {
                if (in_array($preset->key, $existingKeys, true)) {
                    continue;
                }

                FormQuestion::create([
                    'form_id' => $form->id,
                    'order' => $nextOrder++,
                    'label' => $preset->label,
                    'help_text' => $preset->help_text,
                    'type' => $preset->type,
                    'options' => $preset->options,
                    'required' => false,
                    'preset_key' => $preset->key,
                    'target_field' => $preset->target_field,
                ]);
            }
        });

        return response()->json(['record' => $form->fresh('questions')]);
    }

    public function storeQuestion(Request $request, Form $form)
    {
        $data = $this->validatedQuestion($request);
        $data['order'] = (int) ($form->questions()->max('order') ?? -1) + 1;

        $question = $form->questions()->create($data);

        return response()->json(['record' => $question], 201);
    }

    public function updateQuestion(Request $request, Form $form, FormQuestion $question)
    {
        abort_unless($question->form_id === $form->id, 404);

        $question->update($this->validatedQuestion($request));

        return response()->json(['record' => $question]);
    }

    public function destroyQuestion(Form $form, FormQuestion $question)
    {
        abort_unless($question->form_id === $form->id, 404);

        $question->delete();

        return response()->json(null, 204);
    }

    /**
     * Persists a full new order for every question on the form — the
     * builder's drag-reorder UI sends the complete ordered id list each
     * time rather than individual position deltas.
     */
    public function reorderQuestions(Request $request, Form $form)
    {
        $data = $request->validate([
            'question_ids' => 'required|array',
            'question_ids.*' => 'integer|exists:form_questions,id',
        ]);

        DB::transaction(function () use ($data, $form) {
            foreach ($data['question_ids'] as $order => $id) {
                FormQuestion::where('id', $id)->where('form_id', $form->id)->update(['order' => $order]);
            }
        });

        return response()->json(['record' => $form->fresh('questions')]);
    }

    private function validatedQuestion(Request $request): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:255',
            'help_text' => 'nullable|string',
            'type' => ['required', Rule::in(FormQuestion::TYPES)],
            'options' => 'nullable|array',
            'options.*' => 'string|max:255',
            'required' => 'nullable|boolean',
            'target_field' => 'nullable|string|max:255',
        ]);

        if (! in_array($data['type'], FormQuestion::CHOICE_TYPES, true)) {
            $data['options'] = null;
        }

        return $data;
    }
}
