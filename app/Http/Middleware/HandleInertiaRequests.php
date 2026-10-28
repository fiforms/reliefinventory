<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\Warehouse;
use App\Models\LabelSetting;
use App\Models\OfflineModeSetting;
use App\Services\BannerService;
use App\Services\PinLoginService;
use App\Services\TrainingMode;
use App\Services\Warehouses\WarehouseContext;
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
            // Which warehouse this page belongs to (MULTI_WAREHOUSE.md §3): every request the page makes names it
            // (bootstrap.js), so a tab left on one warehouse can't act on another; and browser storage is kept
            // per warehouse with `storageSuffix` (empty for the default warehouse, which keeps its old keys).
            'warehouse' => fn () => self::warehouseProps(),
            // A Training Mode tap-your-name session (capped to station work) — the layout shows a strip saying so.
            'training' => [
                'session' => TrainingMode::isTrainingSession(),
                'team' => TrainingMode::isTrainingSession() ? $request->user()?->training_team : null,
            ],
            'pinLogin' => [
                'switchUserAvailable' => $request->user()
                    ? app(PinLoginService::class)->switchUserAvailable($request)
                    : false,
            ],
            // How many warehouses have invited this login (MULTI_WAREHOUSE.md §6); the profile menu shows a link only when > 0.
            'pendingInvitations' => fn () => $request->user()?->account_id
                ? MembershipInvitation::open()->where('email', $request->user()->email)->count()
                : 0,
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

    /** @return array{id: string, name: string, multiple: bool, storageSuffix: string} */
    public static function warehouseProps(): array
    {
        $warehouse = Warehouse::current();

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->display_name,
            'multiple' => app(WarehouseContext::class)->multiple(),
            'storageSuffix' => $warehouse->is_default ? '' : '_'.strtolower($warehouse->id),
        ];
    }
}
