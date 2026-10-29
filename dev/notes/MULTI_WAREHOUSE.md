# Multi-warehouse instances: design and rationale

An instance hosts several **warehouses**, each a complete, isolated dataset. This file is the *why*. For how it is wired and the
rules that bite, read [MULTI_WAREHOUSE_NOTES.md](MULTI_WAREHOUSE_NOTES.md) first; where the code differs from this file, the code and
its class comments win. Read both before touching sessions, auth, storage paths, backups, the queue, or anything else that assumes
"one instance = one database".

**Section numbers are cited from code comments** (`MULTI_WAREHOUSE.md §6`, etc.). Don't renumber. §14, §17 and §18 were removed
and are not reused.

## Status by section

| § | Topic | Status |
|---|---|---|
| 1–2 | Model, trust model, layout | Built |
| 3 | Resolving the warehouse for a browser | Built |
| 4 | Machine clients (`/w/<id>` URLs) | Built |
| 5 | Keys (`APP_KEY` vs `WAREHOUSE_KEY`) | Built |
| 6 | Central accounts, memberships, sole-membership rule, removal | Built. Removal waits 30 days after archiving in the web UI (`WarehouseManager::REMOVAL_WAIT_DAYS`) and is instant from `warehouse:remove`; both are intended |
| 7 | Database access, provisioner, setup codes | Built |
| 8 | Hand-rolled `WarehouseContext` | Built |
| 9 | Queue, scheduler, Reverb | Built |
| 10 | Maintenance and upgrades | Statuses built; **hold-all upgrades not built** (TODO) |
| 11 | Backups and restore | Built; instance restore is CLI-only (TODO) |
| 12 | Training as a warehouse | Built (`TRAINING_WAREHOUSE.md`) |
| 13 | Other seams | Built, except the full **instance console** (TODO) |
| 15 | Moving existing installs | Done (one-time) |
| 16 | Future | Not built |

Open work is in [../TODO.md](../TODO.md) ("Multi-warehouse").

## 1. What and why

A Docker instance can host several warehouses. A warehouse is a complete dataset: inventory, receiving, shipping, settings, people,
devices, trusts, backups.

- One MariaDB schema per warehouse, plus a control schema describing all warehouses on the instance.
- Per-warehouse storage directory, encryption key and DB user.
- A login is pinned to one warehouse at a time.
- Any instance can add a standard **training** warehouse; none is created automatically (§12).
- Existing installs keep working: the current schema is registered as warehouse 1, and with a single warehouse the chooser is hidden.

**Which installs get it:** every install that runs the Docker image, including the **RI Launcher** and the **R Station local
server**. Only **bare metal** (systemd) stays single-warehouse. The image is shared but the compose files aren't: the Launcher embeds
`docker/compose.yaml` (`launcher/core/src/compose.rs`) and regenerates it on every start, and the R Station has its own
`rstation/product/offline/compose.yaml`. A compose change must reach both, and the Launcher's compose test must still pass.

### Trust model

Design for **warehouses whose managers don't trust each other**, under one **fully trusted instance admin**.

- **Who uses it.** Usually one organization hosting its subsidiaries, but the design must also hold for a hosting provider selling
  shared warehouse hosting to customers who don't know each other. Build for the second case; the first gets it free. The protections
  here are requirements, not extras.
- **An instance admin is assumed able to run arbitrary commands in the container or on the hardware.** So software never enforces a
  rule *against* the instance admin (no forced retention, no read-only access to warehouse data, no hiding secrets). Where such a
  rule is wanted it is policy or an audit trail, not a control. Software enforces isolation *between warehouses*.
- **Instance admin: fully trusted.** Creates, suspends, removes and restores warehouses; global backups; updates; enters any
  warehouse through an audited support session (§6).
- **Warehouse admin: trusted inside their own warehouse only.** Assume one may be hostile to every other warehouse and the instance.
  They control their own data, settings, uploads, imports and restores (so they can feed in any SQL dump and any `accounts.json`),
  can read their own `WAREHOUSE_KEY`, and can read everything in their schema. They must **not** be able to:
  - read or change another warehouse's data, files, keys, sessions or jobs;
  - change an account beyond what the sole-membership rule allows (§6), or take over a login used elsewhere;
  - gain instance-admin powers or affect instance settings (mail, Turnstile, TLS, updates);
  - learn which other warehouses exist or who belongs to them, beyond what the chooser lists (§3);
  - forge anything the instance or another warehouse trusts (account links, machine credentials, job payloads).
- **Out of scope:** a compromised web or queue container or host (exposes every warehouse, §7); a malicious instance admin; denial of
  service through shared CPU, disk and database (no quotas; per-user connection limits only); timing side channels between warehouses.
- **Test for any later design question:** *could a hostile warehouse admin use this against another warehouse or the instance?* If
  yes, it's a defect, even if every warehouse admin we know is friendly.

**Non-goals (for now):** cross-warehouse reporting, per-warehouse quotas, self-service warehouse creation, one-click moves between
instances (backup/restore supports it, the UI doesn't), shared data between warehouses.

## 2. Vocabulary and layout

| Thing | Where it lives |
|---|---|
| **Control schema** | `warehouses` (id, schema name, display name, administrator email, `status`, `listed`, `is_training`, migration state, notes), `warehouse_domains` (hostname → warehouse, primary flag, `skip_chooser`), instance audit log, queue tables (§9) |
| **Accounts schema** | `accounts` (credentials only) and `memberships` (§6); reached only by the auth and account code |
| **Warehouse schema** | Everything that was a `reliefinventory` database before |
| **Warehouse storage dir** | `<storage>/warehouses/<id>/files/`: private uploads, print PDFs, screenshots, status files, chunked uploads |
| **Warehouse secrets** | `/secrets/warehouses/<id>/`: `warehouse.key` and the DB password file. Never in the database or a backup zip. A separate tree, not under any `Storage` disk root, so a path-traversal bug in a file-serving route, or a backup that zips the storage dir, can't reach them |

**Warehouse ID:** random, opaque, immutable, never reused; 8 characters of Crockford base32 (no `0 O 1 I L`), e.g. `K7Q2X9MD`. Not
derived from the federation public key (that can be regenerated; the ID must not move). A full uuid is also stored and written into
backup manifests (lineage). The schema name is *stored*, never derived: warehouse 1 keeps its schema; others are
`reliefinventory_<id>` (lower case). Sequential names (`reliefinventory2`) were rejected because names get reused after a delete.

## 3. Resolving the warehouse for a browser

Order of resolution:

1. **Mapped hostname** (`warehouse_domains`): *locked*; `?w` and the selector cookie are ignored on a mapped host.
2. **`?w=<id>`** on a landing URL: sets the selector cookie and redirects to the clean URL.
3. **Selector cookie.**
4. **Chooser** (login page), unless only one warehouse exists.

**Deployment stance:** a hostname per warehouse is the recommended way to run several, but several warehouses on a **single hostname
or IP are fully supported**, not a degraded mode. Wildcard DNS/TLS can be hard to arrange, and training must never depend on it. So
everything in this section and §4 works on one hostname. Say plainly in the docs that warehouses on one host share a browser origin:
script in one warehouse's pages (stored XSS, an uploaded file shown inline) runs with the host's origin, and "one session at a time"
limits what it can reach. Serve user uploads with `Content-Disposition` and a sandboxing CSP, and recommend separate hostnames more
strongly when one party hosts several *organizations*.

- **One session cookie name.** The session id is looked up in the chosen warehouse's DB, so a cookie from warehouse A is just "not
  logged in" in B: "one warehouse at a time" holds structurally, not by check.
- **Tab guard (fails closed).** The selector cookie is browser-wide, so two tabs on two warehouses would fight. Every page knows its
  warehouse (shared Inertia prop) and sends `X-RI-Warehouse` on each JSON call; a mismatch gives 409 "you switched warehouse in
  another tab". Plain form posts and downloads carry `_w`. CSRF doesn't catch this: the `XSRF-TOKEN` cookie is browser-wide, so after
  tab 2 logs in to B, tab 1 (still showing A) sends B's token and runs under B's session, and a "Delete" on record 42 deletes record 42
  *in B*. So when more than one warehouse exists, refuse every non-GET request and Inertia visit that doesn't name the matching
  warehouse (missing = refused). That covers `router.post`, `fetch`, `sendBeacon` and `window.open`. The warehouse id is also stored in
  the session and compared.
- **Browser storage and long-lived cookies.** On one host every warehouse shares `localStorage` and cookies. `PrintMenu.vue` stores
  `ri_print_last_<media>` as a printer id, and printer ids are numbered per warehouse, so after switching a user could get another
  warehouse's printer picked. `pin_device_token` and `device_session` clash the same way. The warehouse id goes into localStorage keys
  and these cookie names (the session cookie keeps one name on purpose).
- **Chooser privacy.** A public dropdown would reveal which organizations share an instance. Each warehouse has a `listed` flag;
  unlisted ones are reached by hostname, `?w=`, or typing a code. Display names are unique on the instance and shown on the login
  page, so a look-alike warehouse is harder to mistake for the real one. Typed codes are rate-limited and give the same answer whether
  or not the code exists. Training is `listed` unless disabled (§12).
- **Generated links** (`/join` QR code, `/vote`, driver portal, emails) always include the warehouse: host if mapped, else `?w=`.
  **Never build an emailed link from the request's `Host` header** (a forged Host would put the attacker's address in a reset email).
  This is `Warehouse::publicUrl()` plus a URL-root switch task, not a trusted-hosts check, which would break installs reached by IP or
  `.local`.
- **Path prefixes for browser pages were rejected**: the Vue pages hard-code absolute `/json/...` URLs.

## 4. Machine clients (print client, R Station Pi, Launcher backup client, federation peers)

Machines address a warehouse **by URL**: its own hostname, or `https://host/w/<id>`. A small pre-routing step strips `/w/<id>` for
*machine surfaces only* (`/api/*`, `/up`, `/.well-known/ri-instance.json`) and resolves the warehouse.

Why: every client already stores one opaque server URL; request signatures cover the PATH, so signing and the shared vectors
(`tests/Fixtures/federation-signing-vectors.json`) are unchanged; the Pi platform submodule (bearer only, URLs built from
`server_url`) is untouched; pairing codes are shown with the warehouse's address. The well-known document reports the warehouse id.
Federation identity, trusts, grants, devices and pairing codes are all per warehouse. An `X-RI-Warehouse` header was not chosen (it
would need a platform change for the bearer-only Pi).

Details that matter:
- **Strip the prefix for routing only.** `TrustAuth` checks signatures against `$request->getRequestUri()` and the print client signs
  the full path including `/w/<id>`. Signatures must be checked against the *original* URI. Wrong one way, every prefixed client is
  refused; wrong the other way, a signature made for one warehouse is accepted by another.
- **`RIK-` credentials are tied to the warehouse.** The credential's audience is the host plus the `/w/<id>` prefix (unprefixed
  credentials are unchanged), so a device paired with warehouses A and B on one host can't replay A's credential against B.
- **On a mapped host, refuse a `/w/<other>` prefix**, as for `?w` (§3).
- **Unprefixed machine URLs go to warehouse 1, permanently.** Every device, peer and launcher paired before a second warehouse
  existed uses an unprefixed URL and must keep working. This is the one deliberate exception to "no warehouse known = error" (§9).
- **"Domain verified" needs its own hostname.** On a shared host `DomainVerifier` only proves the hosting party controls the domain,
  so offer `domain_verified` only for a warehouse with its own mapped hostname.

## 5. Keys

- **`APP_KEY` is global per instance** and protects cookie/session transport, account-level signed links, and the instance-admin
  step-up. Losing it only logs everyone out and voids outstanding verification links.
- **`WAREHOUSE_KEY`**, one per warehouse in `/secrets/warehouses/<id>/warehouse.key` (§2), is what `Crypt`, `encrypted` casts, signed
  URLs and password-reset links use inside a warehouse. Restoring or moving a warehouse needs only its key. The "write down your key"
  notice is per warehouse.
- **Middleware order:** `EncryptCookies` (APP_KEY) → `ResolveWarehouse` → swap to `WAREHOUSE_KEY` and the warehouse connection →
  `StartSession`. A per-warehouse cookie key would need the warehouse before the cookie could be decrypted.
- **Gotchas:** clear the `Crypt` facade instance and any static model encrypter after the swap; signed URLs carry the warehouse
  (host or `?w`, inside the signed part); the queue worker swaps per job; `config/backup.php`'s `encrypted_columns` registry
  (test-enforced against model casts) refers to `WAREHOUSE_KEY`.
- **Account-level links use `APP_KEY`, never `WAREHOUSE_KEY`.** A warehouse admin can view their `WAREHOUSE_KEY`, so anything signed
  with it can be forged by them. Fine for the warehouse's own data, not for links about an *account*, which is shared:
  - **Email verification** (`verification.verify`) is signed with `APP_KEY`; otherwise a warehouse admin could forge "this address is
    verified" for an email they don't own.
  - **Password reset links aren't signed.** The link carries a random token compared with a hash in `password_reset_tokens`, which
    **lives in the accounts schema**. In the warehouse schema, an admin could restore a dump with a token hash they chose, for any
    email, and reset that account everywhere.
  - Any future account-level signed link (email change, invitations) follows the same rule.

## 6. Identity: central accounts, one login

Split *who you are* from *what you may do*.

- **`accounts`** (own schema/user, used only by the auth provider): login email, password hash, 2FA/OAuth fields (reserved), status,
  `is_instance_admin`, which is settable only in the instance console or CLI, never from a warehouse or an imported backup.
- **`people`** stays per warehouse (party record, roles, permission overrides, PIN, badge, approval/disabled state) with a nullable
  `people.account_id`. Only login-capable people have an account; donors, drivers and passwordless trainees do not.
- **`memberships (account_id, warehouse_id, person_id, status, is_training)` in the accounts schema is the only record of who belongs
  where.** `people.account_id` is at most a cached copy, checked against `memberships` and rebuilt from it after a restore; never read
  from a dump. Two reasons: a warehouse admin can write any column in their schema through a restore, and PIN, tap and kiosk logins
  don't check a password, so an admin could point a person they can PIN into at the instance admin's account; and the sole-membership
  rule needs to see every warehouse's members, which no per-warehouse DB user can.
- A custom **user provider** verifies the account centrally, then resolves *this warehouse's* person through `memberships`.
  `Auth::user()` and `Auth::id()` remain the warehouse person (about 50 actor-stamp call sites, `sessions.user_id`, PIN trust, banner
  dismissals, login history all keep working). **Never let an account id leak into `getAuthIdentifier()`.**

What this buys: a simple install has **one login** (the setup wizard makes one account that is both instance admin and the warehouse-1
Administrator); the same account can belong to the training warehouse; OAuth/2FA/SSO later are instance-wide by construction; shared
users across warehouses become a membership/invite feature, not an architecture change. In the hosted case, a customer's warehouse
admins manage membership and roles in their own warehouse but can never alter credentials or the instance-admin flag.

### Instance admins
`accounts.is_instance_admin`. Warehouse 1 is not special. The instance console is entered with the same account plus a step-up
re-authentication, no second password. An instance admin who isn't a member of a warehouse enters it through a labelled, time-boxed
**support session** (a synthetic Administrator person, written to that warehouse's audit trail and shown to its admins as a notice),
never by silently creating or elevating real users; these datasets hold donor and recipient personal data. The support person never
gets an account, PIN or badge; it expires with the session; the escalation guards (`assertNoEscalation` and friends) never compare
against it; and it is never deleted afterwards, because records name it as their actor.

Instance-only: software update, Docker/TLS settings, Reverb, slow log, mail server settings (§13), Turnstile keys, creating and
suspending warehouses, global backup. Everything else is warehouse admin.

**Step-up before anything instance-wide.** Being logged in to a warehouse never grants instance-admin powers by itself. Before the
instance console or any instance-wide operation, the person re-enters the account's email and password; it expires after a short time,
like `password.confirm`. It applies however they logged in, and **always** to a session that started from a PIN, tap-login, kiosk or
support session. A PIN is never accepted as the step-up. Instance-admin status is read from `accounts` at that moment, never from
anything stored in a warehouse.

### The sole-membership rule
One definition, used by backups *and* admin powers. A warehouse is an account's **only home** if the account's memberships are in that
warehouse plus any number of **training warehouses** (`warehouses.is_training`; training memberships don't count). Only an instance
admin can set `is_training` (otherwise a warehouse admin could label a real warehouse "training" to keep powers over a shared account).
It is computed from `memberships` only, never `people.account_id`.

- **Backups:** a warehouse backup includes `accounts.json` for its members: password hashes always (key-independent); 2FA/OAuth
  secrets re-encrypted under that warehouse's `WAREHOUSE_KEY` (blanked on restore if the key isn't supplied). Credentials of an account
  that is **not** sole-membership are never exported (identity only; the user resets by email), so one warehouse's admin can't harvest
  hashes of people who also belong elsewhere.
- **Import** (a backup's `accounts.json`, and any people import carrying login emails) matches by email: new → create (never instance
  admin); existing → **keep what is already there, attach the membership as a pending invitation, and list every difference on an
  import report**. An import **never modifies an account linked to another warehouse**. The invitation is accepted by the account's
  owner after logging in; otherwise any warehouse admin could write an `accounts.json` and attach anyone's account (an instance
  admin's included) to their warehouse, changing the victim's sole-membership status, revealing which emails have accounts, and
  setting up a look-alike warehouse to lure them. The Data Import "Names & addresses" importer (it matches people by name + zip) uses
  the same keep-and-report rule on `people` rows rather than overwriting a donor's address.
- **Admin powers:** a warehouse admin may reset the password of, or lock/unlock, an account only when that warehouse is its sole home.
  For an account with other real memberships they can only disable that person's *membership* in their own warehouse (local,
  reversible) and **send a password-reset email** (what anyone can do from the login dialog); they never set or see a password. An
  instance admin can reset or lock any account. Account lock is account-level, which is why it needs this rule. Powers drop the moment
  an account gains a second real membership. An account whose only memberships are in training warehouses has no "real" home, so its
  training warehouse's admins have the sole-home powers while that is its only membership.
- **Global backup** includes the whole accounts schema.

### Login, sessions and account changes
- **Locking or resetting an account ends its sessions everywhere.** Sessions live in each warehouse's own database, so
  `accounts.auth_epoch` is bumped on lock, password change and reset, copied into the session at login and compared on each request
  (the same idea as `trusts.session_epoch`).
- **Login attempt limits are per account, not per warehouse.** The rate limiter's counts live in the accounts schema; otherwise an
  attacker gets a fresh set of tries in every warehouse. "Wrong password", "no such account" and "not a member here" give one identical
  answer (wording and timing); checking the password and *then* saying "not a member" tells a guesser the password was right.
- **Email change** changes it for every warehouse: it requires the current password and confirms the new address through the instance
  mailer (§13) with an `APP_KEY`-signed link. A warehouse admin editing a person changes only the *contact* email (`people.email`),
  never `accounts.email`.
- **Account deletion** from the profile, for an account with other memberships, means "leave this warehouse"; deleting the account is
  offered only when this is its sole home.
- **Email matching** is case-insensitive (`accounts.email` has a case-insensitive unique index).

### Removing a warehouse
When a warehouse's data is removed, **every account that is not multi-membership is removed with it**, by the same sole-membership
test (training excluded). An account that also belongs to another real warehouse just loses this membership. Instance-admin accounts
are never removed by this cascade, and removing a training warehouse never removes an account that has a real membership.

Mechanics: instance admin only; the warehouse goes `archived` first (undoable) and is removed from that state. **The wait is a
decided part of the design, enforced in the web UI only:** the Instance Admin UI removes a warehouse no sooner than 30 days after
archiving (`WarehouseManager::REMOVAL_WAIT_DAYS`), while `warehouse:remove` on the command line removes it at once. That follows the
trust rule in §1: someone who can run commands on the box can't be stopped by software, so the wait guards against a mistaken click,
not against the operator. A final warehouse backup is taken; typed confirmation naming the warehouse; the
schema, DB user, storage dir, `WAREHOUSE_KEY` and `warehouse_domains` rows go with it; the removal is audited in the instance log,
including which accounts were removed. Deleting the removed accounts' `memberships` rows covers their training memberships in one step.

### Migration of existing installs
Each login-capable `people` row's email and password hash was copied into `accounts`, with a `memberships` row and `account_id`.
When a warehouse later moves in from another instance, anyone whose email already has an account keeps *this* instance's password
(keep-what-is-there); tell them before the move.

## 7. Database access

Three kinds of credential:

- **Provisioner**: create/drop schema and user, grants. Held only by the provisioning worker (secret mounted only there); the web
  container never has it. The worker reads a table of plain commands (`create`, `drop`, `reset-training` plus a warehouse id), **not
  serialized Laravel jobs**: the main queue worker un-serializes PHP objects from a table the web container can write, so if it also
  held the provisioner credential a forged job row would be a path from the web container to the provisioner. The worker re-checks each
  command against the control schema (e.g. `reset-training` only for an `is_training` warehouse).
- **Control**: read-only on the `warehouses` schema (plus table-level write on the queue tables, §9). No secrets in the control DB.
- **Per-warehouse user** `ri_<id>`: on its own schema only. The password is a random file next to the key; `Warehouse::connectionConfig()`
  owns it, and migrations and restore run as that user. Grants are an explicit list, not ALL: `SELECT, INSERT, UPDATE, DELETE, CREATE,
  DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES`. No `TRIGGER`, `EVENT`, `CREATE ROUTINE` or `CREATE VIEW` unless
  the app starts using them (then also stop dumping with `--routines --triggers` and refuse dumps containing `DEFINER`); never `FILE`
  or `SUPER`. `max_user_connections` is set per user so one warehouse can't take every connection.

**Why per-warehouse users:** restore executes SQL from an uploaded zip (`RestoreService` / `DatabaseTools`). With one pattern-granted
user a crafted dump could `USE`, read or drop another warehouse; with per-warehouse users it is confined to its own schema, and the
grant list covers the rest.

**Restore security model: two layers, nothing else.**
1. *What the SQL can do* is limited by the DB user's grants. The dump is **not parsed or filtered** (no scan for `USE`, `GRANT`,
   `DEFINER`): a keyword filter would be a weak layer next to the grants, and the server refuses what it would let through anyway.
2. *What the client program can do.* `mariadb` runs inside the app container and has `system`/`\!`, `source`, `tee`/`pager` and
   `LOAD DATA LOCAL INFILE`, none of which grants touch. `DatabaseTools::clientCommand()` passes **`--sandbox`** and
   **`--local-infile=0`** and requires the `mariadb` client (MySQL's has no `--sandbox`). `RestoreClientSandboxTest` runs hostile SQL
   through the real client.

A dump's content is still *its warehouse's own data*: a bad restore can wreck that warehouse and nothing else (the manifest
compatibility check and the pre-restore backup protect against mistakes). The accounts connection is used only by login code with
parameterised queries, so an injection in a normal warehouse query can't reach it.

**What per-warehouse users don't protect against:** the web container reads every warehouse's password file to serve it, so if it is
broken into every warehouse is exposed. They stop SQL-level mistakes and attacks (injection, a query on the wrong connection, a crafted
dump), not code running in the web container. State this plainly in operator docs.

**Two connections per request are fine.** They are separate lazily opened connections on the Docker network. A request does one
indexed query on the control connection (hostname → id, status), then connects as the warehouse user. Credentials come from files, so a
read-only control user can never see a secret. There is no in-memory cache (staleness would delay maintenance/suspend); if profiling
ever shows it, add a tiny APCu cache with a short TTL and explicit invalidation on status change.

**Provisioning is crash-safe and idempotent:** row `provisioning` → create schema + user → migrate → seed essentials
(`FirstRunService`) → write keys → issue a setup code → mark `awaiting_setup` last. No administrator yet; that is the setup code's job.

### New warehouses: setup codes

Only an instance admin creates a warehouse. The new warehouse is handed to whoever will run it with a **one-time setup code**:

1. The instance admin creates the warehouse (display name, optional hostname, contact email). Provisioning runs and the warehouse
   ends in `awaiting_setup`.
2. The console shows the warehouse's address and a **setup code**, and can email both to the contact through the instance mailer (§13);
   the admin can also pass them on by hand.
3. At the warehouse's address, an `awaiting_setup` warehouse shows only "enter your setup code". The code holder then either:
   - **Sets up a fresh warehouse**: logs in with an existing account, or creates one, and becomes its Administrator. Optional example
     data, as in the first-run wizard.
   - **Restores a backup into it** (`RestoreController`): any backup is allowed since the warehouse is empty; it runs as the
     warehouse's own DB user with `--sandbox`, and its `accounts.json` produces invitations, never memberships or credential changes
     (§6). The code holder becomes its Administrator either way.
4. The warehouse goes `active`, and the code is used up.

Details: the code is single-use, tied to one warehouse, expires after 7 days, is stored only as a hash in the control schema, can be
revoked or reissued, and has the same format as a pairing code (`ABCDE-FGHJK`). Entry is rate-limited. Holding a code gives power over
that one empty warehouse only; `is_instance_admin` is never set by this path or imported. Provisioning sets `setup_state.completed_at`
so `RedirectToSetup` and the `/install` wizard never run inside a provisioned warehouse (otherwise the first stranger to reach it would
become its admin). `RI_SETUP_CODE` and `/install` remain for the *instance's* first run only. The instance admin can see which
warehouses are `awaiting_setup` and when each code expires.

## 8. Resolution is hand-rolled, not `spatie/laravel-multitenancy`

A small `WarehouseContext` rather than the package. The package would give tenant-aware queued jobs and an artisan looper, about 100
lines to write; our hard parts are all custom: host/`?w`/cookie/header/URL-prefix resolution, `WAREHOUSE_KEY` and cookie ordering,
credentials from files, hold-all upgrades, status gating, backups. (Not rechecked against the package's current release.) It mirrors
the package's vocabulary, a list of "switch warehouse" tasks (connection, key, storage, cache prefix, session, URL root, log context via
`Log::withContext(['warehouse' => …])`), so adopting it later stays possible.

## 9. Background processes: one process, many warehouses

Compose can't add services dynamically, so there is no process per warehouse.

- **Queue:** one stock `queue:work`. Jobs and failed jobs live in the control schema (separate table-level grants); the payload carries
  the warehouse id (`Queue::createPayloadUsing`); a `JobProcessing` listener bootstraps and tears down the warehouse (connection, key,
  storage, cache prefix). FIFO across warehouses. A restore purges that warehouse's pending jobs. **A job with no warehouse id fails**;
  it is never run against warehouse 1 by default, and the same goes for broadcasts and anything else outside a request (the one
  exception is unprefixed machine URLs, §4). Jobs carrying personal data use `ShouldBeEncrypted`, because `failed_jobs` sits in the
  control schema and would otherwise collect serialized personal data from every warehouse, which then lands in the global backup.
- **Scheduler:** one loop running each scheduled command for every active warehouse (`warehouse:each <command>`).
- **Reverb:** channel names are prefixed with the warehouse id, and `routes/channels.php` checks the prefix is the current warehouse
  (a prefix alone doesn't stop a signed-in user asking for another warehouse's channel).
- Long-lived processes always reset warehouse state between jobs.

## 10. Maintenance and upgrades

- Per-warehouse `status`: `provisioning | awaiting_setup | active | maintenance | restoring | upgrading | held | suspended | archived`,
  enforced by middleware. Laravel's global `php artisan down` is not used for restores or training reset.
- **Upgrade failure = hold all** (*not built*). The `web` entrypoint migrates every warehouse and records per-warehouse
  `migration_state` and the pre-upgrade backup id (a pre-upgrade backup of every warehouse is automatic). If any fails, *all* are held.
  The instance admin sees what failed and may **explicitly unblock the non-failed ones**, with the UI saying that unblocking is a
  commit point. MariaDB DDL isn't transactional, so a failed warehouse may be half-migrated; recovery is restoring its pre-upgrade
  backup (§11). Reason for hold-all: a failed upgrade may need a restore, which is complicated if other warehouses have since taken
  new data.
- **Recovery is not per warehouse.** Code is shared by the instance, so a failed warehouse restored from its pre-upgrade backup is
  back on the old schema and the new code will fail the same way. The real choices are **fix forward** with a patch release (the failed
  warehouse stays held), or **roll back the image and restore every warehouse** (losing anything entered in unblocked ones). The
  unblock screen should say exactly that.
- There are three sets of migrations (control, accounts, warehouse), each with its own credential. Run control and accounts first,
  then the warehouses. A failure in control or accounts holds the whole instance.
- Migrating N warehouses at start-up delays the web container: raise the healthcheck grace period or serve a "being upgraded" page.
  A pre-upgrade backup of every warehouse multiplies upgrade time and disk by N: check free space first and refuse to start if short.

## 11. Backups and restore

Operator detail is in [BACKUPS.md](BACKUPS.md).

- **Warehouse backups** (by that warehouse's admin; scope database | full | storage) are schema-name-independent (no `CREATE
  DATABASE`/`USE`), carry warehouse id + uuid + migration list + SHA-256s + `accounts.json` (§6), and **never contain keys**.
- **Restore into one warehouse, touching nothing else:** set it `restoring` → load the dump as its own DB user with `--sandbox` (§7),
  wiping only its tables → replace only its storage dir → keep its own `WAREHOUSE_KEY` (encrypted columns from a different key are
  blanked) → purge its queued jobs → back to `active`. A warehouse admin may restore only backups of the same lineage uuid; restoring
  another warehouse's backup into it (clone/migrate) is instance-admin only. "Create warehouse from backup" extends the first-run
  restore wizard.
- **Live-state tables stay out of warehouse dumps**: `sessions`, `cache`, `cache_locks`, `password_reset_tokens`, leftover `jobs`
  (list in `config/backup.php` beside `encrypted_columns`, with a test that each exists). Otherwise a warehouse cloned from a backup
  starts with working copies of the original's logged-in sessions. `DatabaseTools::dumpCommand()` uses `--ignore-table-data` (keeps the
  `CREATE TABLE`, skips rows; **not** `--ignore-table`, which drops the structure, and `RestoreService` runs `db:wipe` first so the
  tables would be missing). After the load `RestoreService` truncates the same tables, covering older backups.
- **The lineage uuid and SHA-256s prove nothing about origin**: they come from the zip's own manifest. They catch mistakes (wrong file,
  damaged download). What contains a crafted backup is the warehouse's own DB user, `--sandbox` (§7), and the invitation rule (§6).
- **Global backup** (instance admin): control schema dump + accounts + every warehouse + their storage dirs. Keys are listed/exported
  only under the password-confirm + audit rule, per warehouse. The control schema is the map: losing it means every warehouse must be
  re-registered from its own backup manifest.

### Files per warehouse

Every uploaded file goes through the `local` disk (receiving photos, signed BOLs, feedback screenshots, imports, training signatures
and archives, chunked uploads, device releases), and the database stores paths *relative to the disk root*. Nothing uses the `public`
disk. So:
- **Switching the disk is a switch-warehouse task (§8).** Set `filesystems.disks.local.root` to `<storage>/warehouses/<id>/files/` and
  call `Storage::forgetDisk('local')` (disk instances are cached, like the `Crypt` gotcha in §5). Stored paths stay valid and
  controllers don't change. The queue worker does the same per job (§9).
- **One folder per warehouse, one meaning.** A **full** backup is that warehouse's database plus that folder; a **storage** backup is
  the folder alone; a restore replaces only that folder. Backup zips keep their `files/private/…` entries.
- **The `public` disk and `public/storage` symlink are dropped.** One web path can't serve many warehouses' public files without
  mixing them. Any future public file is served through a controller that checks the warehouse.

| Kind | Where | Why not in the warehouse folder |
|---|---|---|
| Secrets (`warehouse.key`, DB password) | `/secrets/warehouses/<id>/` (§2) | Must never be in a backup or reachable by a file route |
| That warehouse's backup zips | `<backup dir>/warehouses/<id>/` | Else every full backup would include earlier backups, and restoring files would delete them |
| Restore and backup status files | next to that warehouse's backups | Must survive the restore that replaces the files folder |
| Logs, slow log, update status, compiled views | instance `storage/` | Instance-wide (§13); never in a warehouse backup |
| Simulator status | the warehouse's files folder | Per warehouse |

Open points: the training class archive (`training-archives/`) must survive a training reset (§12), so the reset keeps that
subfolder; device releases (R Station OS images) are large and per warehouse, so consider leaving them out of backups or making them
instance-wide; and there is **no operating-system separation between warehouses' files**: one PHP process serves all, so separation is
only as good as the app's path handling. The guard test (§13) fails on `storage_path('app…')` and absolute paths outside the helper,
and every route serving a file by stored path must refuse `..` and absolute paths. (Secrets are outside the tree, so a traversal bug
can expose another warehouse's uploads but not its keys.)

## 12. Training as a standard warehouse

Training warehouses are added on request, not provisioned automatically. A new instance gets only warehouse 1. The instance admin can
add a standard **training** warehouse in one step from the instance console: provisioned like any other, seeded with dummy data, marked
`is_training`, listed, and reachable on a single hostname. An install that never runs a class pays nothing for one (this matters on an
R Station). Practice data stops mixing with real data, and because training memberships don't count for the sole-membership rule, the
same people can belong to both a real and a training warehouse. Current behaviour: [TRAINING_WAREHOUSE.md](TRAINING_WAREHOUSE.md).

**In-warehouse Training Mode is removed.** Training is simply a warehouse. That retired everything that made a *real* database behave
like a classroom: the mode flag on `training_settings` (the row stays for event title/location and summary), the permission cap on a
training login (`TrainingMode::TRAINING_KEYS`), the "reset point" backup, `TrainingReset` / `training:reset`, and the `CheckPermission`
training responses. Nothing about a class can put a production database into maintenance mode.

- **Seeding:** from dummy data (`WarehouseActivitySeeder` / simulator material). Seeding from a real warehouse's own data is the later
  feature below.
- **Reset is simple and local:** drop and re-provision *that warehouse* from its seed (no dump/restore, no pre-reset backup, no
  maintenance mode for anyone else). Same code path as provisioning.
- **Keeping members across a reset:** the member list lives in `memberships` (§6), which also records each member's role bundle for
  training warehouses, so a reset re-creates the people with their roles.
- **Class features that stay inside the training warehouse:** trainee tap-login and self sign-up (`/training-login`, `/join`), report
  cards and certificates (and the activity log they read), training products with Prepare Training (setup packet, W-pallet trays,
  table-spot labels), team sheets and station guides. They are gated by "this warehouse `is_training`" and exist only there. Tap-login
  trainees stay passwordless people with no account (`is_trainee`, `account_id` null). A reset wipes class data, but the **class
  archive** (sign-ups CSV, report cards, certificates, written to files outside the schema before a reset) is kept.
- **Training switch.** The switch's only job is to **enable or disable the training warehouse**; it never changes how a live warehouse
  behaves. Disabled, the warehouse refuses everything (logins by password, PIN or tap; `/join`; `/training-login`; its machine URLs),
  keeps its data, and is left out of the chooser. This keeps the old protection: an always-on listed training warehouse would offer
  password-free tap-login and collect `/join` sign-ups (real attendees' contact details) to anyone who can reach the instance. Training
  admins (`manage-training`) and instance admins can flip it. Tap-login still needs an approved trusted device; an approved device's
  `/login` goes to `/training-login`, everyone else gets the normal login page.
- **Later: warehouse admins deploying their own customised training warehouses**, seeded from their catalog and locations. Open
  questions: personal data must be sanitised (safe default: catalog/items/locations only); warehouse admins can't create warehouses
  today, so it needs an approval flow or an allowance; whether the real warehouse's admins administer it and how the sole-membership
  rule treats it; limits, since there are no quotas.

## 13. Other seams

- Hard-coded single-DB spots: `DatabaseTools`, `BackupService`, `SlowQueryLog`, `SetupReset`, `RestoreService`, `config/backup.php`,
  `docker/entrypoint.sh` (DB wait, first-run detection), `docker/db-backup.sh`, `docker/scheduler.sh`.
- Every `storage_path()` / `Storage::disk()` caller goes through one `Warehouse::storagePath()`, with a **guard test** that fails on a
  literal connection name or a raw storage path outside the helper (§11, "Files per warehouse").
- Per warehouse: banner, kiosk settings, printers/devices, trusts and federation identity, backups, domains. Instance only: software
  update, Docker/TLS, Reverb, slow log, **mail server settings**, Turnstile keys.
- **Mail settings are instance-wide.** `MailSetting` (mailer, From, Resend key, SMTP details, feedback recipients) lives in the control
  schema and `/setup/email` moves to the instance console. Password-reset and verification emails are account-level: if a warehouse
  admin could point their warehouse's mailer at their own server they could request a reset for any address and read the link. With
  one instance mailer, the reset email a warehouse admin may send for a shared account (§6) is harmless. The two encrypted secrets use
  `APP_KEY` and belong to the global backup, not warehouse backups.
- `setup:*` commands and the simulator run against a chosen warehouse (`--warehouse`), defaulting to warehouse 1 only while there is
  one warehouse. With more than one, destructive commands (`setup:reset`, the simulator, `backup:restore`) refuse to run without an
  explicit `--warehouse`.
- **Instance console** (partly built; the rest is in TODO). It owns mail settings, Turnstile keys, software updates, Docker/TLS,
  Reverb, logs, per-warehouse disk use, creating warehouses and issuing setup codes, suspending/archiving/removing, global backups and
  support sessions, entered with §6's step-up. Still to do: split `SystemAdmin.vue`, `/setup/email` and `/setup/testing` into instance
  and warehouse parts.
- **Tests:** multi-warehouse tests need several warehouse schemas plus control and accounts, a per-warehouse DB user and a test
  provisioner, while keeping "never the real database" ([../TESTING.md](../TESTING.md)). `tests/SecondWarehouse.php` provides this.
- No quotas; size `table_open_cache` for N × ~100+ tables. Show disk use per warehouse in the console (one warehouse's photos and
  backups fill the shared disk for everyone), and keep "one backup at a time" instance-wide (one `flock` file) so scheduled backups
  don't all hit the database at once.
- **Turnstile:** keys are instance-wide, but each Cloudflare widget lists the hostnames it accepts. Mapping a new hostname must add it
  there, or `/join`, `/vote` and registration fail on that host. It belongs in the domain-mapping steps, and the app warns when a
  mapped host isn't confirmed.
- **Logs:** `laravel.log` and the slow-query log mix every warehouse's data, so pages showing them belong in the instance console
  (`/setup/testing` is `admin-system`, a warehouse permission today). Every log line carries the warehouse id (a switch task, §8).
- **Feedback reports** record the warehouse id as well as the host.

## 15. Moving existing instances to the model (done)

There were no production deployments, only test instances, so this was an in-place upgrade with no manual steps and no lasting
compatibility code. Summary of what it did:
- **Schema:** the existing schema keeps its name and is registered as warehouse 1 (the Docker default warehouse's schema is renamed
  once to `reliefinventory_<id>`).
- **Keys:** the old `APP_KEY` became warehouse 1's `WAREHOUSE_KEY` (so encrypted columns, the federation private key above all, kept
  working). A **new `APP_KEY`** was generated, because the old one had been visible to warehouse 1's admins and `APP_KEY` now signs
  account-level links (§5). Mail secrets were re-encrypted as they moved to the control schema. Everyone was logged out once.
- **Files:** moved into the standard layout; warehouse 1 has no special-case paths.
- **Accounts:** created only for people with a password hash or part-way through registration/invitation; never for `is_trainee`
  people or contact-only people (donors, partners). The migration wrote a report of who got an account.
- **Training data** on test instances stayed in warehouse 1 as ordinary data; no training warehouse was created (§12).
- **Paired devices and peers** kept working through unprefixed machine URLs (§4).
- **Old backups** restore into warehouse 1 or a new warehouse; post-restore migrations bring them up to date. Turning their login
  people into accounts runs outside the warehouse's own migrations (its DB user can't reach the accounts schema) and follows §6's
  invitation rule.

## 16. Documented, not built

- **Cross-warehouse membership UI and invites.** The §6 schema allows one account in several warehouses; the UI, the "join this
  warehouse" acceptance flow, and the privacy question of revealing whether an email has an account are deferred. **Instance-wide
  2FA, OAuth and federated login** attach to `accounts`.
- Syncing credentials between warehouses was rejected in favour of central accounts: synced hashes drift, and key-dependent encrypted
  columns (2FA secrets) can't be copied raw. Federation between warehouses on one instance remains possible but isn't needed for login.
- Per-warehouse quotas, cross-warehouse reporting, self-service warehouse creation, and warehouse-admin-deployed training warehouses
  (§12).
