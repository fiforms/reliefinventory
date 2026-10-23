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
 * Adding a guide: add it to GUIDES (and PAGE_GUIDES, for its page's (?) button), write resources/views/reports/help/<slug>.blade.php
 * (extending reports.help.layout), and build its page on Components/HelpPage.vue —
 * the route, print-queue registry entry, Print button and its line on the /help list (with
 * "Print all guides") all follow from GUIDES.
 */
class HelpReportController extends Controller
{
    /** slug => PDF title. The slug is also the view name and the URL segment. */
    public const GUIDES = [
        'receiving' => 'Receiving Guide',
        'donation-offers' => 'Donation Offers Guide',
        'sorting' => 'Donation Sorting Guide',
        'inventory-movement' => 'Inventory Movement Guide',
        'order-entry' => 'Order Entry Guide',
        'order-filling' => 'Order Filling Guide',
        'shipping' => 'Shipping Guide',
        'items' => 'Master Item List Guide',
        'people' => 'Partner & Donor Info Guide',
    ];

    /**
     * Page path (no leading slash) => guide slug. The layout shows a (?) button on exactly these
     * pages (shared Inertia prop `helpGuide`), linking to /help/<slug>. The main-menu Help tile
     * opens /help, which lists every guide.
     */
    public const PAGE_GUIDES = [
        'receiving' => 'receiving',
        'receiving/offers' => 'donation-offers',
        'donation-sorting' => 'sorting',
        'inventory-movement' => 'inventory-movement',
        'pallet-management' => 'inventory-movement',
        'order-entry' => 'order-entry',
        'order-filling' => 'order-filling',
        'shipping' => 'shipping',
        'setup/items' => 'items',
        'setup/people' => 'people',
    ];

    /** The guide for a request path, or null when the page has none. */
    public static function guideForPath(string $path): ?string
    {
        return self::PAGE_GUIDES[trim($path, '/')] ?? null;
    }

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

    public function showAll()
    {
        return $this->allBuilder();
    }

    /**
     * Every guide in one PDF, each starting on a new page, in GUIDES order. Each guide's own
     * view is rendered on its own first (renderSections) so its title/content sections can't
     * collide with the combined view's — the guides themselves stay single-guide views.
     */
    public function allBuilder(): PdfBuilder
    {
        $guides = [];
        foreach (self::GUIDES as $slug => $title) {
            $guides[] = [
                'title' => $title,
                'content' => view('reports.help.'.$slug)->renderSections()['content'],
            ];
        }

        return Pdf::view('reports.help.all', ['guides' => $guides])
            ->driver('weasyprint')
            ->format('letter')
            ->name('help-guides.pdf');
    }
}
