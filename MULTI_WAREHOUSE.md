# Multi-warehouse instances — design

**Status: designed, not built** (written 2026-10-27, from a design discussion between Daniel and Claude; Mark agrees with the
direction). Nothing here exists in code yet. Read this before touching sessions, auth, storage paths, backups, the queue, or
anything else that assumes "one instance = one database".

## 1. What and why

A Docker instance should be able to host **several warehouses**. A warehouse is a complete dataset exactly as an instance is
today: inventory, ACS numbers, receiving, shipping, settings, people, devices, trusts, backups — everything.

- One MariaDB schema per warehouse, plus a control schema describing all warehouses on the instance.
- Per-warehouse storage directory, per-warehouse encryption key, per-warehouse DB user.
- A login is pinned to one warehouse at a time.
- Every instance has a standard **training** warehouse.
- Existing installs keep working unchanged: the current schema is registered as warehouse 1, and with a single warehouse the
  chooser is hidden.

**Feasibility:** good. If the *default DB connection* is re-pointed per request, Eloquent models and queries need no changes, and
sessions/cache/queue (all `database` driver today) follow the connection. The work is in the seams listed below.

### Non-goals (for now)
Cross-warehouse reporting, per-warehouse quotas, self-service warehouse creation, moving a warehouse between instances as a one-click
operation (the backup/restore design supports it, the UI doesn't), shared data between warehouses.

## 2. Vocabulary and layout

| Thing | Where it lives |
|---|---|
| **Control schema** (`warehouses`) | `warehouses` (id, schema name, display name, administrator email, created, `status`, `listed`, `is_training`, migration state, notes), `warehouse_domains` (hostname → warehouse, primary flag, `skip_chooser`), instance-level audit log, queue tables (see §9) |
| **Accounts schema** | `accounts` — credentials only; reached only by the auth provider (§6) |
| **Warehouse schema** | Everything that is a `reliefinventory` database today |
| **Warehouse storage dir** | `<storage>/warehouses/<id>/` — private uploads, print PDFs, screenshots, status files, chunked uploads, and `warehouse.key` + the DB password file (never in the database, never in a backup zip) |

**Warehouse ID:** random, opaque, immutable, never reused; 8 characters of Crockford base32 (no `0 O 1 I L`), e.g. `K7Q2X9MD`.
Not derived from the federation public key — that key can be regenerated and the ID must not move. A full uuid is also stored and
written into backup manifests (lineage). The schema name is *stored*, never derived: warehouse 1 stays `reliefinventory`, others are
`reliefinventory_<id>` (lower case). Sequential numbering (`reliefinventory2`…) was rejected: names get reused after a delete.

## 3. Resolving the warehouse for a browser

Order of resolution:

1. **Mapped hostname** (`warehouse_domains`) — *locked*: `?w` and the selector cookie are ignored on a mapped host.
2. **`?w=<id>`** on a landing URL — sets the selector cookie and redirects to the clean URL.
3. **Selector cookie.**
4. **Chooser** (login page), unless only one warehouse exists.

**Deployment stance (decided 2026-10-27):** a hostname per warehouse is the *recommended* way to run several warehouses, but
multiple warehouses on a **single hostname or IP are fully supported** — not a degraded mode. Wildcard DNS/TLS can be hard to arrange
in some places, and the standard training warehouse must never depend on it. So everything in this section and §4 has to work on one
hostname; per-warehouse hostnames are the cleaner option (a stable, prefix-free address for machines) and nothing more.

- **One session cookie name.** The session id is looked up in the chosen warehouse's DB, so a cookie from warehouse A is simply "not
  logged in" in B. This gives "one warehouse at a time" structurally rather than by check.
- **Tab guard.** The selector cookie is browser-wide, so two tabs on two warehouses would fight. Every page knows its warehouse
  (shared Inertia prop) and sends `X-RI-Warehouse` on each JSON call; a mismatch → 409 "you switched warehouse in another tab".
  Plain form posts carry a hidden `w`.
- **Chooser privacy.** A public dropdown would reveal which organizations share an instance. Each warehouse has a `listed` flag;
  unlisted ones are reached by hostname, `?w=`, or typing a code. Training is `listed`.
- **Generated links** (the `/join` QR code, `/vote`, driver portal, emails) always include the warehouse: host if mapped, else `?w=`.
- **Path prefixes for browser pages were rejected**: the Vue pages hard-code absolute `/json/...` URLs.

## 4. Machine clients (print client, R Station Pi, Launcher backup client, federation peers)

Address a warehouse **by URL**: its own hostname, or `https://host/w/<id>`. A small pre-routing step strips `/w/<id>` for *machine
surfaces only* (`/api/*`, `/up`, `/.well-known/ri-instance.json`) and resolves the warehouse.

Why: every client already stores one opaque server URL; request signatures cover the PATH, so signing and the shared vectors
(`tests/Fixtures/federation-signing-vectors.json`) are unchanged; the Pi platform submodule (sends only a bearer, builds URLs from
`server_url`) is untouched; pairing codes simply get shown together with the warehouse's address. The well-known document reports the
warehouse id. Federation identity, trusts, grants, devices, pairing codes are all per warehouse (`federation_identities.tenant_id` and
`trusts.identity_id` were already reserved for this).

Alternative if explicit protocol selection is preferred later: an `X-RI-Warehouse` header (needs a platform change for the bearer-only
Pi). Not chosen.

## 5. Keys

- **`APP_KEY` stays global per instance** and protects only cookie/session transport (and the instance-admin step-up).
- **`WAREHOUSE_KEY`** — one per warehouse, in `<storage>/warehouses/<id>/warehouse.key` — is what `Crypt`, `encrypted` casts, signed
  URLs and password-reset links use inside a warehouse.
- **Middleware order:** `EncryptCookies` (APP_KEY) → `ResolveWarehouse` → swap to `WAREHOUSE_KEY` + warehouse connection →
  `StartSession`. This avoids the chicken-and-egg problem (a per-warehouse cookie key would need the warehouse before the cookie
  could be decrypted).
- **Benefit:** the key that matters travels with the warehouse — restoring or moving one needs only its `WAREHOUSE_KEY`. Losing
  `APP_KEY` merely logs everyone out. The "write down your key" notice becomes per warehouse.
- **Gotchas to test:** clear the `Crypt` facade instance and any static model encrypter after the swap; signed URLs must carry the
  warehouse (host or `?w`, inside the signed part); the queue worker swaps per job; `config/backup.php`'s `encrypted_columns`
  registry (test-enforced against model casts) now refers to `WAREHOUSE_KEY`.

## 6. Identity: central accounts, one login

Split *who you are* from *what you may do*.

- **`accounts`** (own schema/user, used only by the auth provider): login email, password hash, 2FA/OAuth fields (reserved), status,
  `is_instance_admin` — settable only in the instance console or CLI, never from a warehouse or an imported backup.
- **`people`** stays per warehouse (party record, roles, permission overrides, PIN, badge, approval/disabled state) with a nullable
  `people.account_id`. Only login-capable people have an account; donors, drivers and passwordless training trainees do not.
- A custom **user provider** verifies the account centrally, then resolves *this warehouse's* person. `Auth::user()` and
  `Auth::id()` remain the warehouse person (about 50 actor-stamp call sites, `sessions.user_id`, PIN trust, banner dismissals,
  login history, `training_login_person_id` all keep working). **Never let an account id leak into `getAuthIdentifier()`.**

What this buys: a simple install has **one login** (the setup wizard makes one account that is both instance admin and the
warehouse-1 Administrator); the same account can be a member of the training warehouse; OAuth/2FA/SSO later are instance-wide by
construction; shared users across warehouses become a membership/invite feature instead of an architecture change. It also covers the hosted case
(one party runs an instance for several organizations): a customer's warehouse admins can manage membership and roles in their own
warehouse but can never alter credentials or the instance-admin flag, so they cannot take over the instance — which a design that put
the instance admin on a person row in some warehouse could not guarantee.

### Instance admins
`accounts.is_instance_admin`. Warehouse 1 is not special. The instance console is entered with the same account plus a step-up
re-authentication — no second password. An instance admin who is not a member of a warehouse enters it through a labelled,
time-boxed **support session** (a synthetic Administrator person, written to that warehouse's audit trail and visible to its admin),
never by silently creating or elevating real users — these datasets hold donor and recipient personal data.
Instance-only: software update, Docker/TLS settings, Reverb, slow log, instance mail fallback, Turnstile keys, creating/suspending
warehouses, global backup. Everything else is warehouse admin.

### The sole-membership rule
One definition, used by backups *and* by admin powers. A warehouse is an account's **only home** if the account's memberships are in
that warehouse plus any number of **training warehouses** (`warehouses.is_training`; training memberships do not count). Only an
instance admin can set `is_training` (otherwise a warehouse admin could label a real warehouse "training" to keep powers over a
shared account).

- **Backups:** a warehouse backup includes `accounts.json` for its members: password hashes always (key-independent); 2FA/OAuth
  secrets re-encrypted under that warehouse's `WAREHOUSE_KEY` (blanked on restore if the key isn't supplied). Credentials of an
  account that is **not** sole-membership are never exported (identity only → the user resets by email), so one warehouse's admin
  cannot harvest hashes of people who also belong elsewhere. "Someville Warehouse + Someville Training" exports fully.
- **Import** (a backup's `accounts.json`, and any people import that carries login emails) matches by email: new → create (never
  instance admin); existing → **keep what is already in the database, attach the membership, and list every difference on an
  import report** (imported name/phone/etc. vs. what was kept) for a human to look at. An import **never modifies an account that is
  linked to another warehouse**, whatever options a future import might offer. (Not yet checked: the Data Import "Names & addresses"
  importer matches people by name + zip, not email, and today *updates* a match — decide whether this keep-and-report rule extends to
  it; it concerns `people` rows, not accounts.)
- **Admin powers:** a warehouse admin may reset the password of, or lock/unlock, an account only when that warehouse is its sole
  home. For an account with other real memberships the warehouse admin can only disable that person's *membership* in their own
  warehouse (local, reversible) and **send a password-reset email** — the same thing anyone can do from the login dialog, so it is
  not dangerous; the admin never sets or sees a password for it. An instance admin can reset/lock any account. Account lock is account-level (all warehouses), which
  is exactly why it needs the test. Powers drop at the moment an account gains a second real membership.
  **Unsettled edge case:** an account whose only memberships are in training warehouses has no "real" home, so the rule as written
  gives no warehouse admin authority over it; decide whether the training warehouse's admin and instance admins cover it.
- **Global backup** includes the whole accounts schema.

### Removing a warehouse
Decided: when a warehouse's data is removed, **every account that is not multi-membership is removed with it** — the same
sole-membership test (training warehouses excluded), so an account that belonged only to the removed warehouse (and any training
warehouse) goes; an account that also belongs to another real warehouse just loses this membership and is untouched. Instance admin
accounts are never removed by this cascade. Removing a training warehouse never removes an account that has a real membership.
Proposed mechanics (not yet agreed): instance admin only; the warehouse goes `archived` first and is removed from that state; a final
warehouse backup is offered/taken; typed confirmation naming the warehouse; the schema, DB user, storage dir, `WAREHOUSE_KEY`
and `warehouse_domains` rows go with it; the removal is audited in the instance log (including which accounts were removed).

### Migration of existing installs
Copy each login-capable `people` row's email and password hash into `accounts`, set `account_id`. Single-warehouse installs see no
visible change.

## 7. Database access

Three kinds of credential:

- **Provisioner** — create/drop schema and user, grants. Held only by the queue container (secret mounted only there); the web
  container never has it. Provisioning runs as a job.
- **Control** — read-only on the `warehouses` schema (plus table-level write on the queue tables, §9). No secrets in the control DB.
- **Per-warehouse user** `ri_<id>` — ALL on its own schema only. Password is a random file next to the key; owned by
  `Warehouse::connectionConfig()`, so provisioning creates/drops the user and migrations/restore run as that user.

**Why per-warehouse users (recommended, not optional):** restore executes SQL from an uploaded zip (`RestoreService` /
`DatabaseTools`). With a single pattern-granted user, a crafted dump could `USE`, read or drop another warehouse; with per-warehouse
users it is confined to its own schema. Dumps are *also* still rejected if they contain `USE`, `CREATE DATABASE` or `GRANT`. The same
property makes a wrong-connection bug or an SQL injection fail closed. The accounts connection is used only by the login code with
parameterised queries, so an injection in a normal warehouse query (running as the warehouse user) cannot reach it.

**Two connections per request are fine.** They are separate lazily opened Laravel connections on the Docker network (~ms); nothing
"reconnects". A request does one indexed query on the control connection (hostname → id, status) and then connects as the warehouse
user. Credentials come from files, so a read-only control user can never see a secret. No in-memory cache initially (FPM has none
anyway, and staleness would delay maintenance/suspend). If it ever shows in profiling, add a tiny APCu cache with a short TTL and
explicit invalidation on status change. A selector cookie or `?w` already names the id, so only hostname mapping needs the lookup.

**Provisioning is crash-safe and idempotent:** row `provisioning` → create schema + user → migrate → seed essentials
(`FirstRunService`) → create the administrator from the *administrator email* → write keys → mark `active` last.

## 8. Resolution is hand-rolled, not `spatie/laravel-multitenancy`

Decision: write a small `WarehouseContext` rather than adopting the package. (Not checked against Laravel 13; verify support if this
is reconsidered.) The package would give tenant-aware queued jobs and an artisan looper — roughly 100 lines to write. Our hard parts
are all custom: host/`?w`/cookie/header/URL-prefix resolution, `WAREHOUSE_KEY` and the cookie ordering, credentials from files,
hold-all upgrades, status gating, backups. Mirror its vocabulary (a list of "switch warehouse" tasks: connection, key, storage, cache
prefix, session) so adopting it later stays possible.

## 9. Background processes: one process, many warehouses

Compose can't add services dynamically, so there is no process per warehouse.

- **Queue:** keep one stock `queue:work`. Jobs and failed jobs live in the control schema (separate table-level grants); the payload
  carries the warehouse id (`Queue::createPayloadUsing`); a `JobProcessing` listener bootstraps and tears down the warehouse
  (connection, key, storage, cache prefix). FIFO across warehouses, no looping worker. A restore purges that warehouse's pending jobs.
- **Scheduler:** one loop running each scheduled command for every active warehouse (`warehouse:each <command>`).
- **Reverb:** channel names prefixed with the warehouse id, or `print-queue` and friends would leak across warehouses.
- Long-lived processes must always reset warehouse state between jobs.

## 10. Maintenance and upgrades

- Per-warehouse `status`: `provisioning | active | maintenance | restoring | upgrading | held | suspended | archived`, enforced by
  middleware. Laravel's global `php artisan down` is no longer used for restores or training reset.
- **Upgrade failure = hold all.** The `web` entrypoint migrates every warehouse and records per-warehouse `migration_state` and the
  pre-upgrade backup id (a pre-upgrade backup of every warehouse is automatic). If any fails, *all* are held. The instance admin sees
  what failed and may **explicitly unblock the non-failed ones**. The UI must say that unblocking is a commit point: code can no
  longer be rolled back for those warehouses. MariaDB DDL isn't transactional, so a failed warehouse may be half-migrated; recovery is
  restoring its pre-upgrade backup (§11), which touches only that warehouse. Reason for hold-all: a failed upgrade may need a restore,
  which is complicated if other warehouses have since been entering new data.

## 11. Backups and restore

- **Warehouse backups** (taken by that warehouse's admin; scope database | full | storage) are schema-name-independent (no
  `CREATE DATABASE`/`USE`), carry warehouse id + uuid + migration list + SHA-256s + `accounts.json` (§6), and **never contain keys**
  (existing rule).
- **Restore into one warehouse, touching nothing else:** set it `restoring` → load the dump as its own DB user, wiping only its
  tables → replace only its storage dir → keep its own `WAREHOUSE_KEY` (encrypted columns from a different key are blanked, as
  today) → purge its queued jobs → back to `active`. A warehouse admin may restore only backups of the same lineage uuid; restoring
  another warehouse's backup into it (clone/migrate) is instance-admin only. "Create warehouse from backup" extends the first-run
  restore wizard (`RestoreController`).
- **Global backup** (instance admin): control schema dump + accounts + every warehouse + their storage dirs. Keys are listed/exported
  only under the existing password-confirm + audit rule (`view-app-key` equivalent, per warehouse). The control schema is the map —
  losing it means every warehouse must be re-registered from its own backup manifest.

## 12. Training as a standard warehouse

Every instance gets a `training` warehouse (`is_training`, listed, reachable on a single hostname). Practice data stops mixing with
real data. Because training memberships don't count for the sole-membership rule, the same people can belong to both a real and a
training warehouse without losing their backup or admin-reset behaviour.

**Decided 2026-10-27: in-warehouse Training Mode is deprecated and its code removed.** Training is simply a warehouse. Concretely that
retires the machinery that existed to make a *real* database behave like a classroom: the on/off switch (the mode flag on
`training_settings` — the row itself stays for event title/location and the prepared summary), the
permission cap on a training login (`TrainingMode::TRAINING_KEYS` intersected in `HasPermissions`; the Trainee role still limits
what a trainee can do, and the warehouse is disposable), the "reset point" database backup
taken when the mode is switched on, `TrainingReset` / `training:reset` (a whole-database restore in maintenance mode), and the
`CheckPermission` training-specific responses. Nothing about a class should be able to put a production database into maintenance mode.

- **Seeding:** a training warehouse is provisioned from a seed — dummy data (the existing `WarehouseActivitySeeder` / simulator
  material), or optionally a copy of a real warehouse's data. A copy of real data must be sanitised: people, donors, partners,
  contact details and uploaded files are personal data, so the safe default is catalog/items/locations only (or names scrambled).
- **Reset is deliberately simple and local:** drop and re-provision *that one warehouse* from its seed (no dump/restore, no pre-reset
  backup, no maintenance mode for anyone else). It touches nothing outside the training warehouse and is the same code path as
  provisioning, so it needs no separate machinery.
- **Design point to settle:** a member's membership is a `people` row inside the warehouse schema, so a reset would delete everyone's
  training membership. The reset must re-create it — e.g. snapshot the membership list (account → role bundle) in the control schema
  before the wipe and re-apply it after — or keep the account list in the control schema for training warehouses.
- **Class features that survive inside the training warehouse (decided 2026-10-27):** trainee tap-login and self sign-up
  (`/training-login`, `/join`), report cards and certificates (and the always-on activity log they read from), training products
  with Prepare Training (and its setup packet, W-pallet trays and table-spot labels), and the team sheets / station guides. They are
  gated by "this warehouse `is_training`" instead of the removed mode switch, and they exist only in training warehouses. Consequences:
  tap-login trainees stay passwordless people with no account (`is_trainee`, `account_id` null) and the placeholder
  `trainee-<id>@training.invalid` emails can go (the login-capable test becomes "has an account, or is a trainee"); a reset wipes the
  class data (trainees, sign-ups, practice records, activity log), so the existing **class archive** (sign-ups CSV, report cards,
  certificates, written to files outside the schema before a reset) is kept unless it proves to complicate the simple reset. How the
  training login page is offered (today: an approved trusted device redirects `/login` to `/training-login`) needs re-deciding now
  that there is no instance-wide switch — likely "the login page of a training warehouse is the tap-login".

## 13. Other seams (carry-over checklist)

- Hard-coded single-DB spots: `DatabaseTools`, `BackupService`, `SlowQueryLog`, `SetupReset`, `RestoreService`, `config/backup.php`,
  `docker/entrypoint.sh` (DB wait, first-run detection), `docker/db-backup.sh`, `docker/scheduler.sh`.
- Every `storage_path()` / `Storage::disk()` caller (receiving photos, driver portal, print PDFs, feedback screenshots, chunked
  uploads, simulator/training status files) → one `Warehouse::storagePath()`, plus a **guard test** that fails on a literal
  connection name or a raw storage path outside the helper.
- Per warehouse: mail, banner, kiosk settings, printers/devices, trusts and federation identity, backups, domains. Instance only:
  software update, Docker/TLS, Reverb, slow log, instance mail fallback, Turnstile keys.
- Warehouse simulator and `setup:*` commands run against a chosen warehouse (`--warehouse`), default warehouse 1.
- No quotas initially; size `table_open_cache` for N × ~100+ tables.

## 14. Auth surface audit (read-only, 2026-10-27)

What central accounts (§6) touches. Key fact: `people.email` is both the donor/partner **contact** email and the **login**
identifier; "who can log in" is implicit in the `User` global scope `whereNotNull('email')` (`app/Models/User.php:80`). The only
unique email index is `people.email`. Trainees use placeholder `trainee-<id>@training.invalid` emails purely to satisfy that scope —
with `account_id = null` they go away.

**Columns.** Move to `accounts`: email (login), password, `email_verified_at`, future 2FA/OAuth; `remember_token` may stay on
`people` (SessionGuard reads it from the resolved user — lower risk). Stay per warehouse on `people`: `disabled_at`, `approved_at` /
`approved_by_person_id`, `requested_track`, `pin_hash`, `badge_code`, `password_change_required` (arguable), `is_trainee` /
`training_team`, `created_by_person_id`, contact email (no longer unique).

**Work items** (S/M/L):

| # | Item | Size |
|---|---|---|
| 1 | Schema + migration of existing rows; backup/restore compatibility | L |
| 2 | Auth `UserProvider`, `User`/`Person` accessors, `routeNotificationForMail`, replace the `User` scope with "has an account, or is a trainee" | M |
| 3 | Registration, verification, approval; "existing account joins this warehouse" path; re-keyed verify link; banned-email check at account creation | M |
| 4 | Password reset / change / confirm brokers on accounts; `BackupController` key reveal (reads `Auth::user()->password`) | M |
| 5 | `UserAdminController` split into account vs person writes (`setPassword`, `applyAction`, list/CSV, email uniqueness rules, escalation guards) | M–L |
| 6 | Profile: email change, account delete | S |
| 7 | CLI + first run: `FirstRunService`, `admin:bootstrap`, `user:create` (hard-coded role id 15), `user:promote`, `user:deactivate`, password command, `SetupController` | M |
| 8 | PIN / kiosk / trusted devices (mostly unchanged — person id preserved) | S |
| 9 | Training login, preparer, signup (drop placeholder emails, keep the session key) | S |
| 10 | Sessions (`sessions.user_id` must stay the person id; deactivate does not end existing sessions today — consider forced logout) | S |
| 11 | Contact-vs-login email at the mail/notification call sites | S–M |
| 12 | Tests: `UserFactory` creates account + person (keeps ~60 of ~99 auth-touching files green); ~38 auth-related files updated | M |
| 13 | Multi-warehouse policy items below | S as design |

**Riskiest spots**
1. The `people.email` double role plus the data migration — a wrong split locks people out or exposes contacts as accounts; backup,
   restore and federation compatibility ride on it.
2. Provider id semantics — PIN unlock, training login, first-run and registration each call `Auth::login(User::find(...))` directly
   and bypass credential checks; they must all go through one helper.
3. Reset / verification / `UserAdminController` actions (`reactivate`, `approve`, `require_password_change`, `setPassword`,
   `resend_invite`) become cross-warehouse takeover surfaces; the current escalation checks (`assertNoEscalation`,
   `assertHoldsEverything`, `assertCanTakeOver`) don't model them. The sole-membership rule governs them.

**Decisions to settle while writing the accounts change:** `accounts.email` is canonical and who may change it; `people.email`
becomes contact-only (drop unique); per-warehouse approval when a shared account joins; a password reset resolves to the account
(changes it everywhere — intended, it is one login); mapping `is_instance_admin` into `HasPermissions` (instance-level checks only,
not warehouse permission keys); `FirstRunService::administratorExists()` semantics; `is_trainee` people stay passwordless tap-login with no account (decided: the class features survive, §12). **Decided since:** a warehouse admin may send a reset *email* for a multi-membership
account but never set a password (§6); an import keeps existing details, reports differences and never changes an account linked to
another warehouse (§6); removing a warehouse removes its non-multi-membership accounts (§6). **Not audited:** `app/Services/Import`
beyond the Names & addresses importer noted in §6.

## 15. Phases

0. **Invisible refactor:** `Warehouse`, `warehouse_domains`, `WarehouseContext`; the existing database becomes warehouse 1; single
   mode is a no-op, the full test suite is unchanged.
   **0b. Accounts layer** (can ship before any second warehouse exists): `accounts`, `people.account_id`, provider, migration of
   existing logins, `UserFactory`.
1. Per-warehouse storage dir and `WAREHOUSE_KEY` (warehouse 1 keeps its existing key and paths); `storagePath()` helper + guard tests.
2. Resolution (hostname / `?w` / cookie / header / machine URL prefix), key swap, queue / scheduler / Reverb.
3. Provisioning (including per-warehouse DB users), chooser, domain mapping, the training warehouse (seeded; simple local reset) and
   **removal of in-warehouse Training Mode** (§12), warehouse removal with the account cascade (§6).
4. Per-warehouse maintenance status, backup, single-warehouse restore, hold-all upgrades, global backup.
5. Instance console, support sessions, instance-vs-warehouse split in `SystemAdmin.vue`.

## 16. Documented, not built (future)

- **Cross-warehouse membership UI and invites** (the schema in §6 already allows one account in several warehouses; building the UI,
  the "join this warehouse" acceptance flow and the privacy question of revealing whether an email already has an account are
  deferred). **Instance-wide 2FA, OAuth and federated login** attach to `accounts`.
- Syncing or sharing credentials by other routes was considered and rejected in favour of central accounts: syncing hashes between
  warehouse `people` rows drifts, and encrypted columns such as 2FA secrets are key-dependent so cannot be copied raw.
  Federation between warehouses on one instance remains possible but is not needed for login.
- Per-warehouse quotas, cross-warehouse reporting, self-service warehouse creation.

## 17. Open questions

Resolved 2026-10-27 (kept here for the record): a hostname per warehouse is recommended and single-hostname/IP multi-warehouse is
fully supported (§3); in-warehouse Training Mode is removed and training is a seeded warehouse with a simple local reset (§12); a
warehouse admin may send a reset email for a multi-membership account but not reset the password (§6); imports keep existing details
and report differences, and never touch accounts linked to other warehouses (§6); removing a warehouse removes its
non-multi-membership accounts (§6).

Still open:
- How the training login page is offered without the instance-wide switch and trusted-device redirect (§12), and whether the
  class archive stays.
- Keeping a training warehouse's member list across a reset (§12 design point).
- An account whose only memberships are in training warehouses: who has authority over it (§6).
- Whether the keep-and-report import rule extends to the Names & addresses `people` importer (§6).
- Removal mechanics (§6): archive-first, final backup, typed confirmation — proposed, not agreed.
