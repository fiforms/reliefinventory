# Warehouse simulator and slow-query tools

For testing the whole system — screens, reports, data entry — against realistic volume, and for finding what is slow.
Neither is needed in normal operation.

## The simulator

Fills a **brand-new** instance with about a year of fictional warehouse activity: donors and partners (names from a
built-in list, businesses as "Adjective Adjective Noun Suffix"), donation offers and Receiving, sorting of every pallet
into usable/outdated/trashed/diverted lines, put-away onto warehouse pallets and re-slotting, partner requests, filling
(with allocation approvals, partial and split fills), shipping with drivers, delivery, and signed-BOL review (including
rejected-and-resubmitted ones). Activity has a weekday rhythm, two disaster surges and slow growth.

Default size (365 days, `--orders=1200`): ~480 donations, ~1,200 requests / ~4,400 request lines, ~12,000 inventory
movements, ~5,000 pallets and containers, ~230 people, ~65 drivers, ~120 donation offers, 77 locations. About a minute.

### Running it

| How | When |
|---|---|
| First-run wizard, step 2: **Simulate a year of activity** | a new instance (also Docker). Starts the run in the background and lands on Setup > Testing Tools, which shows progress. |
| `php artisan simulate:warehouse` | any empty instance with an administrator. `--days=365 --orders=1200 --errors=4 --seed=N --yes`; `--background` returns at once, then `php artisan simulate:status`. |
| Setup > Testing Tools > Start simulation | an empty instance, from the browser (`admin-system`). |

Docker: `docker compose exec app php artisan simulate:warehouse`.

**It refuses to run on an instance that has data** — any donation, request, pallet, inventory movement, driver, offer or
location, or any person who isn't the placeholder donor or a role-holding account. There is deliberately no force flag.
Simulated data can't be removed from the app; to start clean, reset the database (`docker compose down -v`, or restore a
backup / `php artisan setup:reset`).

### What "realistic" means here

* **No negative stock** — except on purpose. The simulator tracks stock as it goes and never fills more than is on hand
  at that moment. A few over-picks (`--errors`, default 4: a picker keys more than the shelf held) are injected so you
  can see how the app copes; they are listed after the run (and on the Testing Tools page). Later donations may bring an
  item back above zero. Tests replay the whole ledger in time order to prove nothing else ever goes negative.
* About 85-95% of requested units end up filled; some lines are short, a few requests close unfulfilled.
* Everything is real history: `created_at`, `status_changed_at`, pallet status history, offer status logs and
  partner-status logs carry the simulated date, made through the same model methods the controllers use.
* **Seeded**: `--seed=N` reproduces a run exactly (same catalog). The seed is printed and stored.
* One database transaction: a failed run leaves the instance exactly as it was.

Not simulated: volunteer kiosk sign-ins, feedback reports, form submissions, print jobs, user logins/sessions.
Stock is pooled by item (as in the app); the warehouse pallets are a realistic *population* that is opened and emptied as
fills draw the item down, not a per-pallet quantity ledger.

## Finding slow screens

**Setup > Testing Tools** (and `php artisan slowlog:show`):

* **Slow pages** — whole requests that were slow, ran 60+ queries, or sent 1 MB+. This is the view that finds most real
  problems: a list loading thousands of rows is made of fast queries. "Response" large with little "In database" means too
  much data sent at once; "Queries" high means a query repeated per row (N+1).
* **Queries** grouped by statement shape (numbers and strings blanked, worst total time first) or newest first.
  The app log never records query values.
* **MariaDB's own slow log** (adds rows examined and lock time) when the server writes it to `mysql.slow_log` and the app
  user may read it. The Docker stack arranges both; elsewhere run
  `SET GLOBAL slow_query_log=1, long_query_time=0.25, log_output='TABLE'; GRANT SELECT ON mysql.slow_log TO 'reliefinventory'@'%';`.

Switch the app-side recording on with `RI_SLOW_QUERY_MS=250` (milliseconds; 0 = off, the default outside Docker). Related:
`RI_SLOW_REQUEST_MS` (1000), `RI_CHATTY_REQUEST_QUERIES` (60), `RI_HEAVY_RESPONSE_BYTES` (1048576). Files:
`storage/logs/slow-queries.jsonl` (+ one rotated `.1`, 5 MB each).

## How it is built

`app/Services/Simulator/` — `WarehouseSimulator` (the run), `NameBank` (names/places), `SimRandom` (seedable randomness),
`SimulationRun` (detached launch + progress file `storage/app/simulator-status.json`). A day runs: receive donations ->
sort pallets -> create requests -> fill -> complete filling -> assign driver -> ship -> deliver -> BOL review -> re-slot
pallets, each phase starting no earlier than its usual hour and the clock never running backwards, so stock is always
sorted before it is picked. Slow-query side: `SlowQueryLog`, `config/slowlog.php`, `TestingToolsController`,
`TestingTools.vue`, `slowlog:show`, the Docker provisioner (grants read access to, and prunes, the slow-log table). Tests: `WarehouseSimulatorTest`, `SlowQueryLogTest`.
