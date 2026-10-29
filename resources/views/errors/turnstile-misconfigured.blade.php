<!DOCTYPE html>
<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
<!--
    Shown instead of a bare 500 when Cloudflare Turnstile is switched on but
    its keys are missing (see TurnstileService::misconfiguredResponse()).
    Self-contained, like 503.blade.php. The visitor may be a stranger, so it
    only names the setting names, never any values.
-->
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Relief Inventory') }} — Setup needed</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f9f9f9; color: #1f2933; }
        .wrap { max-width: 40rem; margin: 3rem auto; padding: 0 1rem; }
        .card { background: #fff; border: 1px solid #d9dee3; border-radius: 8px; padding: 1.5rem; }
        h1 { margin-top: 0; font-size: 1.3rem; }
        code { background: #eef1f4; padding: 0.1rem 0.3rem; border-radius: 3px; font-size: 0.9em; }
        pre { background: #eef1f4; padding: 0.75rem; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class="wrap"><div class="card">
    <h1>This page isn't set up yet</h1>
    <p>This page uses Cloudflare Turnstile (a bot check), which is switched on but has no keys. The server's administrator needs to do one of the following, then restart the application:</p>
    <p><strong>Turn it off</strong> (fine for a private or offline site) — set <code>CLOUDFLARE_TURNSTILE_ENABLED</code> to <code>false</code>.</p>
    <p><strong>Or give it keys</strong> — set <code>CLOUDFLARE_TURNSTILE_ENABLED</code> to <code>true</code>, and set both <code>CLOUDFLARE_TURNSTILE_SITE_KEY</code> and <code>CLOUDFLARE_TURNSTILE_SECRET_KEY</code> (free from the Cloudflare dashboard; the server needs Internet access).</p>
    <p>In Docker, these go in the settings block at the top of <code>compose.yaml</code>; then run:</p>
    <pre>docker compose up -d</pre>
</div></div>
</body>
</html>
