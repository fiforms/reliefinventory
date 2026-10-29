<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\Warehouse;
use App\Models\LabelSetting;
use App\Models\OfflineModeSetting;
use App\Services\BannerService;
use App\Services\InstanceRecoveryKeys;
use App\Services\PinLoginService;
use App\Services\Warehouses\ProvisioningClient;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Throwable;

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
            // For instance administrators on a Docker install: the instance has no recovery key yet (or nobody confirmed storing it), so the layout
            // shows a banner until it does. Null for everyone else, so no one else pays a query or sees it.
            'recoveryKey' => fn () => self::recoveryKeyProps($request),
            // A single instance-wide flag for "this warehouse has no
            // reliable internet right now" — pages that call out to
            // Turnstile/geocod.io check this before even attempting the
            // request, rather than firing it and eating a slow timeout or
            // a 503. See OfflineModeSetting.
            'offlineMode' => OfflineModeSetting::isOffline(),
        ];
    }

    /** @return array{state: string}|null */
    public static function recoveryKeyProps(Request $request): ?array
    {
        if (! ProvisioningClient::applies() || ! $request->user()?->account?->is_instance_admin) {
            return null;
        }
        try {
            $state = app(InstanceRecoveryKeys::class)->state();
        } catch (Throwable) {
            return null;   // the control schema is not ready: nothing to say
        }

        return $state === 'ok' ? null : ['state' => $state];
    }

    /** @return array{id: string, name: string, multiple: bool, storageSuffix: string} */
    public static function warehouseProps(): array
    {
        $warehouse = Warehouse::current();

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->display_name,
            'multiple' => app(WarehouseContext::class)->multiple(),
            'training' => (bool) $warehouse->is_training,
            'storageSuffix' => $warehouse->is_default ? '' : '_'.strtolower($warehouse->id),
        ];
    }
}
