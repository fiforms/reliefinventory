# Realtime server (Laravel Reverb)

Reverb is **optional**. It makes the print queue instant — the Print menu's status dots, the
Print Queue screen, "your label printed / failed" notices, and a wake-up ping to print servers
the moment a job is waiting. With it off, everything still works: browsers poll every few
seconds and print servers poll on their own interval. Nothing in the app depends on it being up,
and a Reverb outage never blocks a print (publishing has a 1-2 second timeout and swallows
errors — see `App\Services\PrintBroadcaster`).

It's a separate long-running PHP process (`php artisan reverb:start`) that holds WebSocket
connections, and it needs its own one-time provisioning per server — it is **not** part of
`update.sh`'s setup (but `update.sh` does restart it on deploy once the unit below exists).

## Enable it

1. **`.env`** — generate the app credentials once with `php artisan reverb:install` (it writes
   `REVERB_APP_ID/KEY/SECRET`), then set the rest. Typical production behind nginx:

   ```
   BROADCAST_CONNECTION=reverb

   # What the Reverb process itself binds to (nginx proxies to it)
   REVERB_SERVER_HOST=127.0.0.1
   REVERB_SERVER_PORT=8080

   # The public address — browsers connect here, and so does the app when publishing
   REVERB_HOST=ri.example.org
   REVERB_PORT=443
   REVERB_SCHEME=https
   ```

   `VITE_REVERB_*` (already in `.env.example`) mirror those for the browser build, so
   **re-run `npm run build` after changing them** — they're baked in at build time.

   *Optional:* if you'd rather have the app publish to Reverb locally instead of looping out
   through nginx, set `REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http`, keep the
   browsers on the public address with explicit `VITE_REVERB_HOST/PORT/SCHEME`, and give print
   servers the public address with `REVERB_PUBLIC_HOST/PORT/SCHEME` (see `config/printing.php`).

2. **systemd** — install the unit, adjusting `User=`, `WorkingDirectory=` and the PHP binary to
   match this box's queue-worker unit:

   ```bash
   sudo cp scripts/systemd/reliefinventory-reverb.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now reliefinventory-reverb.service
   ```

   `scripts/update.sh` restarts a unit named `<app-dir-name>-reverb` (override with
   `REVERB_SERVICE=...`) after each deploy if it exists, so new code is picked up.

3. **nginx** — proxy WebSocket upgrades and Reverb's HTTP publish API to the local process,
   inside the site's existing `server { ... }` block (TLS is already terminated there, so
   browsers and print servers use `wss://` on port 443 and no extra firewall port opens):

   ```nginx
   location /app {
       proxy_http_version 1.1;
       proxy_set_header Host $http_host;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
       proxy_read_timeout 120s;
       proxy_pass http://127.0.0.1:8080;
   }
   location /apps {
       proxy_set_header Host $http_host;
       proxy_pass http://127.0.0.1:8080;
   }
   ```

   `sudo nginx -t && sudo systemctl reload nginx`.

4. **Rebuild assets and clear caches**: `npm run build && php artisan config:cache`.

## Check it works

- `systemctl status reliefinventory-reverb` shows it running; `journalctl -u reliefinventory-reverb -f`
  logs connections.
- Open the Print Queue page: the footnote under the job list reads **Updating live.** (instead of
  "refreshes every few seconds") once the socket is connected.
- A print server's heartbeat response carries a `print_realtime` object (host, port, scheme,
  `channel`) when this is on, and `null` when it's off.

## Troubleshooting

- **Page says "refreshes every few seconds" instead of "Updating live."** — the browser isn't
  connected. Check the nginx `/app` block, that `VITE_REVERB_*` were set *before* `npm run build`,
  and the browser console for a failed `wss://` connection.
- **Print servers don't get `print_realtime`** — realtime is off: `BROADCAST_CONNECTION` isn't
  `reverb`, or `REVERB_APP_KEY` is empty (the app then quietly runs on polling).
- **Prints work but nothing is instant** — the app publishes to `REVERB_HOST:REVERB_PORT`; if
  that points at a closed port the publish fails fast and is logged, never shown to users. Look
  for `Pusher`/connection errors in `storage/logs/laravel.log`.

## Notes

- **Scaling:** one Reverb on one box is plenty for a site. Horizontal scaling needs Redis
  (`REVERB_SCALING_ENABLED`) and is not set up here.
- **Security:** browsers use private channels authorised through the normal session
  (`routes/channels.php`: `print-queue` needs `manage-print-queue`; `print-printers` and
  `print-user.{id}` need `print-documents`). Print servers listen on a **public channel whose
  random name is the only secret** — each device gets its own, delivered in its authenticated
  heartbeat response, rotated on re-pair and cleared on revoke. The message carries no data
  (it only means "poll now"); claiming and downloading still need the device's bearer token.
  Keep the Reverb app *secret* server-side; the app *key* is public by design.
- **Dev:** `composer run dev` starts `reverb:start` alongside the other processes; with
  `BROADCAST_CONNECTION=log` (the default in `.env.example`) it's simply unused.
