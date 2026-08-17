<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [

    /*
    |--------------------------------------------------------------------------
    | Feedback report notification recipients
    |--------------------------------------------------------------------------
    |
    | Who gets emailed when someone submits an in-app bug/feature report.
    | These are developers, not necessarily anyone holding admin-system —
    | so it's a plain address list, not tied to a permission. Comma-
    | separated. If empty, submission still saves normally; only the
    | notification email is skipped.
    |
    */

    'notify_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FEEDBACK_NOTIFY_EMAIL', ''))
    ))),

];
