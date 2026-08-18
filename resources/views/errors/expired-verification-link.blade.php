<!DOCTYPE html>
<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
<!--
    Friendly replacement for Laravel's raw "403 Invalid signature." page when
    someone taps an email-verification link after its 60-minute signed-URL
    window has passed — most commonly because they're re-opening the link
    from Mail hours later, not because anything actually broke. The account
    is virtually always already verified by then (verification links are
    idempotent and often consumed within seconds by iOS Mail link
    prefetching), so this just points them back to login instead of showing
    a dead end. Self-contained like 503.blade.php — no Vite/app layout
    dependency.
-->
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Relief Inventory') }} — Link Expired</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            height: 100%;
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f9f9f9;
            color: #1f2933;
        }
        .wrap {
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .card {
            max-width: 30rem;
            width: 100%;
            background: #ffffff;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .icon {
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1.25rem;
            color: #007bff;
        }
        h1 {
            font-size: 1.375rem;
            margin: 0 0 0.75rem;
            color: #111827;
        }
        p {
            margin: 0 0 0.5rem;
            line-height: 1.6;
            color: #4b5563;
        }
        .button {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 0.625rem 1.5rem;
            background-color: #007bff;
            color: #ffffff;
            text-decoration: none;
            border-radius: 0.5rem;
            font-weight: 600;
        }
        .button:hover {
            background-color: #0069d9;
        }
    </style>
</head>
<body>
    <div class="wrap" role="main">
        <div class="card">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 7v5l3 2"></path>
            </svg>
            <h1>This link has expired</h1>
            <p>Email verification links only stay valid for a short time after they're sent.</p>
            <p>If you've already set your password, your email is most likely verified already — just log in below.</p>
            <a class="button" href="{{ route('login') }}">Go to login</a>
        </div>
    </div>
</body>
</html>
