<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [

    /*
    |--------------------------------------------------------------------------
    | Low stock threshold
    |--------------------------------------------------------------------------
    |
    | Global default for the Available/Limited/Unavailable three-state
    | availability computation (PROJECT_ANALYSIS.md Part 5). An itemtype's
    | on-hand at or below this is "Limited" (or "Unavailable" at zero);
    | above it is "Available". Overridable per itemtype via
    | itemtypes.low_stock_threshold.
    |
    */

    'low_stock_threshold' => env('INVENTORY_LOW_STOCK_THRESHOLD', 10),

];
