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

    /*
    |--------------------------------------------------------------------------
    | Centralized feedback aggregation
    |--------------------------------------------------------------------------
    |
    | Every instance keeps its own fully-functional local feedback flow
    | regardless of these settings — standing up a new disaster-response
    | instance must never require this to be configured. `instance_role`
    | defaults to 'remote' so a freshly-cloned instance never accidentally
    | thinks it's the central collector; only the one instance the dev team
    | actually triages from should be set to 'central'.
    |
    | `central_ingest_url` / `shared_secret` are only meaningful on a
    | 'remote' instance (where to push, and the secret to authenticate with)
    | and are read by the *central* instance's ingest endpoint to verify
    | incoming pushes — both sides of a given remote/central pair must be
    | configured with the same shared_secret. `instance_identifier`
    | defaults to this instance's own APP_URL host when left blank (see
    | FeedbackPushService::instanceIdentifier()) — set it explicitly only if
    | APP_URL isn't a stable/meaningful identifier for this instance.
    |
    */

    'instance_role' => env('FEEDBACK_INSTANCE_ROLE', 'remote'),

    'instance_identifier' => env('FEEDBACK_INSTANCE_IDENTIFIER'),

    'central_ingest_url' => env('FEEDBACK_CENTRAL_INGEST_URL'),

    'shared_secret' => env('FEEDBACK_SHARED_SECRET'),

];
