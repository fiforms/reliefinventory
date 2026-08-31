<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Form;
use App\Models\FormQuestion;
use App\Models\FormQuestionPreset;
use Illuminate\Database\Migrations\Migration;

/**
 * The first real form built on the generic form-builder tool: Partner
 * Agency Intake. Every preset question, requires approval, and approving a
 * submission creates/links the applying org as a Partner-role Person — the
 * live data model today (no Facility/partner-org entity exists yet; see
 * facility-network-and-allocation-model, Part 5, unbuilt). access_mode
 * 'both' so a prospective partner can fill it out unauthenticated from a
 * shared link, or staff can walk someone through it on the phone while
 * logged in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $form = Form::create([
            'name' => 'Partner Agency Intake',
            'slug' => 'partner-agency-intake',
            'intro_text' => 'Thank you for your interest in partnering with our warehouse. This form '
                .'collects the information we need to evaluate a new partner agency application. '
                .'Please fill it out as completely as you can — an estimate is fine where noted.',
            'status' => 'active',
            'access_mode' => 'both',
            'requires_approval' => true,
            'on_approval_action' => 'create_or_link_partner',
        ]);

        $order = 0;
        foreach (FormQuestionPreset::orderBy('order')->get() as $preset) {
            FormQuestion::create([
                'form_id' => $form->id,
                'order' => $order++,
                'label' => $preset->label,
                'help_text' => $preset->help_text,
                'type' => $preset->type,
                'options' => $preset->options,
                'required' => in_array($preset->key, ['org_name', 'address', 'phone', 'email'], true),
                'preset_key' => $preset->key,
                'target_field' => $preset->target_field,
            ]);
        }
    }

    public function down(): void
    {
        Form::where('slug', 'partner-agency-intake')->delete();
    }
};
