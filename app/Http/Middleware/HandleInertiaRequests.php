<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Http\Controllers\HelpReportController;
use App\Models\LabelSetting;
use App\Models\OfflineModeSetting;
use App\Services\BannerService;
use App\Services\PinLoginService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Slug of the Help guide for this page, if it has one — the layout's (?) button.
            'helpGuide' => HelpReportController::guideForPath($request->path()),
            'pinLogin' => [
                'switchUserAvailable' => $request->user()
                    ? app(PinLoginService::class)->switchUserAvailable($request)
                    : false,
            ],
            'banner' => $request->user()
                ? app(BannerService::class)->propsFor($request->user()->id)
                : ['active' => false],
            'buildingSafety' => [
                'canView' => (bool) $request->user()?->hasPermission('view-building-occupancy'),
            ],
            // Lets the shared Print menu decide whether to offer printers at
            // all (without print-documents it falls back to download only).
            'printing' => [
                'canPrint' => (bool) $request->user()?->hasPermission('print-documents'),
                // The media pallet labels are printed on (LabelSetting): Print menus for pallet labels filter printers by it.
                'palletLabelMedia' => LabelSetting::palletMedia(),
                // Likewise for inventory labels (one per item set put on a pallet).
                'inventoryLabelMedia' => LabelSetting::inventoryMedia(),
            ],
            // Moving an item to another ACS # (Master Item List) is Administrator-only.
            'catalog' => [
                'canMoveItems' => (bool) $request->user()?->hasPermission('admin-system'),
            ],
            'notifications' => [
                'unreadCount' => $request->user()?->unreadNotifications()->count() ?? 0,
            ],
            // A single instance-wide flag for "this warehouse has no
            // reliable internet right now" — pages that call out to
            // Turnstile/geocod.io check this before even attempting the
            // request, rather than firing it and eating a slow timeout or
            // a 503. See OfflineModeSetting.
            'offlineMode' => OfflineModeSetting::isOffline(),
        ];
    }
}
