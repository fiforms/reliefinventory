<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'geocodio' => [
        // Used to suggest a county from an entered address (People/Order
        // Entry) — free tier, no key means the feature just silently
        // stays unavailable (GeocodioService::lookup returns null).
        'key' => env('GEOCODIO_API_KEY'),
    ],

    'turnstile' => [
        // Off by default only in the testing environment (see phpunit.xml).
        // Deployments running on a closed network with no internet access
        // (e.g. a LAN-only warehouse install) should set
        // CLOUDFLARE_TURNSTILE_ENABLED=false in .env — Turnstile requires
        // reaching challenges.cloudflare.com and cannot function offline.
        'enabled' => env('CLOUDFLARE_TURNSTILE_ENABLED', true),
        'site_key' => env('CLOUDFLARE_TURNSTILE_SITE_KEY'),
        'secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
    ],

];
