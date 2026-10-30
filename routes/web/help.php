<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Services\TrainingMode;
use App\Support\HelpGuide;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

/*
 * Help guides and signs
 *
 * The in-app help guides and their PDFs (/help, /report/help/*), and the printable warehouse signs (/setup/signs, /report/sign.pdf).
 *
 * Access: any signed-in user, no permission key. Adding a guide: dev/notes/FRONTEND.md, "Help guides".
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// Help pages: static how-to guides, one per data-entry page, reached from that page's "Help: ..."
// text link (Components/HelpLink.vue) or from the main-menu Help tile's /help list.
// Visible to anyone authenticated (no permission gate).
Route::get('/help', function () {
    return Inertia::render('Help/Index', [
        'breadcrumb' => MenuItem::getBreadcrumb('/help'),
        'guides' => collect(HelpReportController::GUIDES)
            ->map(fn ($title, $slug) => ['slug' => $slug, 'title' => $title])->values(),
        // Training Mode's team activity sheets (TrainingAdminController::teamSheetsBuilder).
        'trainingTeams' => TrainingMode::TEAMS,
    ]);
})->middleware(['auth']);

// Every guide is one Markdown file (resources/help/en/<slug>.md, App\Support\HelpGuide), shown by one page.
Route::get('/help/{guide}', function (string $guide) {
    $source = HelpGuide::load($guide);

    return Inertia::render('Help/Guide', [
        'guide' => $guide,
        // The page heading drops the PDF title's " Guide" ("Receiving Guide" -> "Receiving").
        'title' => Str::beforeLast(HelpReportController::GUIDES[$guide], ' Guide'),
        'intro' => $source->intro,
        'sections' => $source->sections,
        'breadcrumb' => [
            ...MenuItem::getBreadcrumb('/help'),
            ['href' => '/help/'.$guide, 'title' => HelpReportController::GUIDES[$guide]],
        ],
    ]);
})->whereIn('guide', array_keys(HelpReportController::GUIDES))->middleware(['auth']);

// Signs (SignController): ready-made or typed warehouse signs on Letter paper, on the Setup menu.
// Anyone signed in, like the Help guides. /setup/signs/preview is the PDF's own view as HTML.
Route::get('/setup/signs', [SignController::class, 'page'])->middleware(['auth']);
Route::get('/setup/signs/preview', [SignController::class, 'preview'])->middleware(['auth']);
Route::get('/setup/signs/fonts/{file}', [SignController::class, 'font'])
    ->whereIn('file', ['LiberationSans-Bold.ttf', 'LiberationSans-Regular.ttf'])->middleware(['auth']);
Route::get('/report/sign.pdf', [SignController::class, 'pdf'])->name('report.sign')->middleware(['auth']);

// Every guide in one printable PDF (the /help list's "Print all guides").
Route::get('/report/help/all', [HelpReportController::class, 'showAll'])
    ->name('report.help.all')
    ->middleware(['auth']);

// Printable PDF version of a help guide — same no-permission-gate rule as
// the guide pages themselves. One route for every guide listed in
// HelpReportController::GUIDES.
Route::get('/report/help/{guide}',
    [HelpReportController::class, 'show'])
    ->whereIn('guide', array_keys(HelpReportController::GUIDES))
    ->name('report.help')
    ->middleware(['auth']);
