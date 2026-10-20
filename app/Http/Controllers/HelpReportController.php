<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Printable PDF versions of the in-app Help guides (resources/js/Pages/Help/*.vue).
 * Content is duplicated into a Blade view rather than shared with the Vue
 * page — there's no existing mechanism in this codebase for sharing content
 * between an Inertia page and a Blade/PDF view, so this follows the same
 * duplication already accepted elsewhere (e.g. pallet labels).
 *
 * Adding a guide: add it to GUIDES, write resources/views/reports/help/<slug>.blade.php
 * (extending reports.help.layout), and build its page on Components/HelpPage.vue —
 * the route, print-queue registry entry and Print button all follow from GUIDES.
 */
class HelpReportController extends Controller
{
    /** slug => PDF title. The slug is also the view name and the URL segment. */
    public const GUIDES = [
        'receiving' => 'Receiving Guide',
        'sorting' => 'Donation Sorting Guide',
    ];

    public function show(string $guide)
    {
        return $this->builder($guide);
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function builder(string $guide): PdfBuilder
    {
        abort_unless(array_key_exists($guide, self::GUIDES), 404);

        return Pdf::view('reports.help.'.$guide)
            ->driver('weasyprint')
            ->format('letter')
            ->name($guide.'-guide.pdf');
    }
}
