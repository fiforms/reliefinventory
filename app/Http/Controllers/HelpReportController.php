<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Printable PDF versions of the in-app Help guides (resources/js/Pages/Help/*.vue).
 * Content is duplicated into a Blade view rather than shared with the Vue
 * page — there's no existing mechanism in this codebase for sharing content
 * between an Inertia page and a Blade/PDF view, so this follows the same
 * duplication already accepted elsewhere (e.g. pallet labels).
 */
class HelpReportController extends Controller
{
    public function receiving()
    {
        return Pdf::view('reports.help.receiving')
            ->format('letter')
            ->name('receiving-guide.pdf');
    }
}
