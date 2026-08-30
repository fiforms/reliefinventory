<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

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
