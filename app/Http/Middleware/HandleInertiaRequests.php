<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

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
        ];
    }
}
