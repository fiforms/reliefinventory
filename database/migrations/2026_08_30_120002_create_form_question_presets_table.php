<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\FormQuestionPreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seeded catalog backing the admin builder's "add a preset question"
 * checkbox picker (org intake's usual basics, plus a couple generic
 * catch-alls) — checking a box copies an editable FormQuestion onto the
 * form; it's a starting template, not a live binding.
 */
return new class extends Migration
{
    private const PRESETS = [
        ['key' => 'org_name', 'label' => 'Organization Name', 'category' => 'basic_info', 'type' => 'short_text', 'target_field' => 'organization', 'order' => 10],
        ['key' => 'address', 'label' => 'Street Address', 'category' => 'basic_info', 'type' => 'short_text', 'target_field' => 'address', 'order' => 20],
        ['key' => 'city', 'label' => 'City', 'category' => 'basic_info', 'type' => 'short_text', 'target_field' => 'city', 'order' => 30],
        ['key' => 'state', 'label' => 'State', 'category' => 'basic_info', 'type' => 'short_text', 'target_field' => 'state', 'order' => 40],
        ['key' => 'zip', 'label' => 'Zip Code', 'category' => 'basic_info', 'type' => 'short_text', 'target_field' => 'zip', 'order' => 50],
        ['key' => 'phone', 'label' => 'Phone Number', 'category' => 'basic_info', 'type' => 'short_text', 'target_field' => 'phone', 'order' => 60],
        ['key' => 'email', 'label' => 'Email Address', 'category' => 'basic_info', 'type' => 'short_text', 'target_field' => 'email', 'order' => 70],
        ['key' => 'website', 'label' => 'Website', 'category' => 'basic_info', 'type' => 'short_text', 'order' => 80],
        ['key' => 'nonprofit_status', 'label' => '501(c)(3) Status', 'category' => 'basic_info', 'type' => 'single_choice', 'options' => ['Yes', 'No', 'Applied'], 'order' => 90],
        ['key' => 'reference', 'label' => 'Reference Name or Organization', 'category' => 'basic_info', 'type' => 'short_text', 'order' => 100],
        ['key' => 'services_offered', 'label' => 'Services Offered', 'category' => 'other', 'type' => 'long_text', 'order' => 110],
        ['key' => 'locations_served', 'label' => 'Locations Served', 'category' => 'other', 'type' => 'long_text', 'order' => 120],
        ['key' => 'counties_served', 'label' => 'Counties Served', 'category' => 'other', 'type' => 'long_text', 'order' => 130],
        ['key' => 'families_per_week', 'label' => 'Estimated Number of Families Served per Week', 'category' => 'other', 'type' => 'number', 'help_text' => 'A rough estimate is fine.', 'order' => 140],
        ['key' => 'established', 'label' => 'When Was Your Organization Established?', 'category' => 'other', 'type' => 'short_text', 'order' => 150],
        ['key' => 'vision', 'label' => 'Organization Vision or Goal', 'category' => 'other', 'type' => 'long_text', 'order' => 160],
        ['key' => 'other_info', 'label' => 'Other Important Information', 'category' => 'other', 'type' => 'long_text', 'order' => 170],
    ];

    public function up(): void
    {
        Schema::create('form_question_presets', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            // basic_info groups the org-intake basics together in the
            // picker UI; other is everything else. Purely a UI grouping.
            $table->string('category')->default('other');
            $table->string('type');
            $table->json('options')->nullable();
            $table->text('help_text')->nullable();
            $table->string('target_field')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        foreach (self::PRESETS as $preset) {
            FormQuestionPreset::create($preset);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_question_presets');
    }
};
