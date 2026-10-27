<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use Illuminate\Routing\UrlGenerator;

/**
 * Signed URLs about an *account* (email verification today; email-change confirmation and invitations later)
 * are signed with the instance's APP_KEY, not the current warehouse's key: a warehouse admin can read their own
 * WAREHOUSE_KEY, and could otherwise forge "this address is verified" for an account shared with other
 * warehouses (MULTI_WAREHOUSE.md §5). Links about a warehouse's own data keep using the normal `URL` signing.
 */
class InstanceSignedUrl
{
    /** A copy of the URL generator that signs and checks with APP_KEY (the shared one is left alone). */
    public static function generator(): UrlGenerator
    {
        return app('url')->withKeyResolver(fn () => [config('app.key')]);
    }
}
