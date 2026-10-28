# Multi-warehouse instances — design

**Status: being built** on branch `multi-warehouse-design` (designed 2026-10-27, from a design discussion between Daniel and
Claude; Mark agrees with the direction). See "Implementation progress" (§18) for what exists so far. Read this before touching
sessions, auth, storage paths, backups, the queue, or anything else that assumes "one instance = one database".

> Items labelled **Recommended** came from a security review of this plan (2026-10-27). They are the intended implementation but
> haven't been explicitly agreed yet. Confirm or change each one when the phase it belongs to starts.

## 1. What and why

A Docker instance should be able to host **several warehouses**. A warehouse is a complete dataset exactly as an instance is
today: inventory, ACS numbers, receiving, shipping, settings, people, devices, trusts, backups — everything.

- One MariaDB schema per warehouse, plus a control schema describing all warehouses on the instance.
- Per-warehouse storage directory, per-warehouse encryption key, per-warehouse DB user.
- A login is pinned to one warehouse at a time.
- Any instance can easily add a standard **training** warehouse; none is created automatically (§12).
- Existing installs keep working unchanged: the current schema is registered as warehouse 1, and with a single warehouse the
  chooser is hidden.

### Trust model (decided 2026-10-27)

Design for **warehouses whose managers don't trust each other**, under one **fully trusted instance admin**.

- **Who uses it.** In practice, one organization hosting its subsidiary organizations. But the design must also hold for a
  **hosting provider selling shared "warehouse" hosting** to customers who don't know or trust each other. Build for the second
  case; the first gets it for free. So the protections in this plan are requirements, not extras for "if we ever host".
- **Rule of thumb (decided 2026-10-28): an instance admin is assumed to be able to run arbitrary commands inside the container or
  on the hardware that runs the instance.** So software never tries to enforce a rule *against* the instance admin: no forced
  retention ("keep a removed warehouse 30 days"), no "instance admins only get read-only access to warehouse data", no hiding
  secrets from them. Where such a rule is wanted it is a policy or an audit trail, not a control. What the software does enforce is
  isolation *between warehouses* (and from the web container's own mistakes), which is the part an admin of one warehouse could attack.
- **Instance admin: fully trusted.** On a hosted instance, this is the hosting party. Can do anything: create, suspend, remove and
  restore warehouses, global backups, software updates, and enter any warehouse through an audited support session (§6).
- **Warehouse admin: trusted inside their own warehouse only.** Assume a warehouse admin may be hostile to every other warehouse
  and to the instance. They fully control their own data, settings, uploads, imports and restores (so they can feed in any SQL dump
  and any `accounts.json`), they can read their own `WAREHOUSE_KEY`, and they can read everything in their own schema. Using all of
  that, they must **not** be able to:
  - read or change another warehouse's data, files, keys, sessions or jobs;
  - change an account beyond what the sole-membership rule allows (§6), or take over a login used elsewhere;
  - gain instance-admin powers, or affect the instance's settings (mail, Turnstile, TLS, updates);
  - learn which other warehouses exist or who belongs to them, beyond what the chooser lists (§3);
  - forge anything the instance or another warehouse trusts (account links, machine credentials, job payloads).
- **Users, devices, peers and the public:** as today, inside the warehouse they reach.
- **Out of scope:** a compromised web or queue container, or a compromised host (that exposes every warehouse, §7); a malicious
  instance admin; denial of service through shared CPU, disk and database (no quotas yet; per-user connection limits only, §7);
  timing side channels between warehouses.
- **Rule of thumb for any later design question:** *could a hostile warehouse admin use this against another warehouse or the
  instance?* If yes, it's a defect, even if every warehouse admin we know today is friendly.

**Feasibility:** good. If the *default DB connection* is re-pointed per request, Eloquent models and queries need no changes, and
sessions/cache/queue (all `database` driver today) follow the connection. The work is in the seams listed below.

### Non-goals (for now)
Cross-warehouse reporting, per-warehouse quotas, self-service warehouse creation, moving a warehouse between instances as a one-click
operation (the backup/restore design supports it, the UI doesn't), shared data between warehouses.

**Which installs get it (decided 2026-10-27):** every install that runs the Docker image, which includes the **RI Launcher** and
the **R Station local server** (both run the same image). Only **bare metal** (systemd, `update.sh` tiered backups) stays
single-warehouse. One thing to keep in step: the image is shared, but the compose files aren't. The Launcher embeds
`docker/compose.yaml` (`launcher/core/src/compose.rs`) and regenerates it on every start, while the R Station has its own
`rstation/product/offline/compose.yaml`. So any compose change this plan needs (the secrets volume, the provisioner credential, the
provisioning worker, §7) has to reach both, and the Launcher's compose test must still pass.

## 2. Vocabulary and layout

| Thing | Where it lives |
|---|---|
| **Control schema** (`warehouses`) | `warehouses` (id, schema name, display name, administrator email, created, `status`, `listed`, `is_training`, migration state, notes), `warehouse_domains` (hostname → warehouse, primary flag, `skip_chooser`), instance-level audit log, queue tables (see §9) |
| **Accounts schema** | `accounts` — credentials only — and `memberships` (§6); reached only by the auth and account code |
| **Warehouse schema** | Everything that is a `reliefinventory` database today |
| **Warehouse storage dir** | `<storage>/warehouses/<id>/files/` — private uploads, print PDFs, screenshots, status files, chunked uploads |
| **Warehouse secrets** | `/secrets/warehouses/<id>/` — `warehouse.key` + the DB password file (never in the database, never in a backup zip). **Recommended:** a separate tree, not under any `Storage` disk root, so a path-traversal bug in a file-serving route (receiving photos, training archives, print PDFs, chunked uploads), or a backup that zips the storage dir, can never reach them |

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
in some places, and a training warehouse must never depend on it. So everything in this section and §4 has to work on one
hostname; per-warehouse hostnames are the cleaner option (a stable, prefix-free address for machines) and nothing more.
**Recommended:** say plainly in the docs that warehouses on one host share a browser origin. Script running in one warehouse's pages
(a stored XSS, or an uploaded file displayed inline) runs with the host's origin; "one session at a time" limits what it can reach.
Serve user uploads (receiving photos, uploaded PDFs) with `Content-Disposition` and a sandboxing CSP, and when one party hosts
several *organizations*, recommend separate hostnames more strongly than for one organization's own warehouses.

- **One session cookie name.** The session id is looked up in the chosen warehouse's DB, so a cookie from warehouse A is simply "not
  logged in" in B. This gives "one warehouse at a time" structurally rather than by check.
- **Tab guard.** The selector cookie is browser-wide, so two tabs on two warehouses would fight. Every page knows its warehouse
  (shared Inertia prop) and sends `X-RI-Warehouse` on each JSON call; a mismatch → 409 "you switched warehouse in another tab".
  Plain form posts and download links carry `_w`.
  **Recommended: the guard fails closed.** Laravel's CSRF check won't catch a switched warehouse: the `XSRF-TOKEN` cookie applies
  to the whole browser, so after tab 2 logs in to B, tab 1 (still showing A) sends B's token, passes CSRF and runs under B's
  session. A "Delete" on record 42 in tab 1 would delete record 42 *in B*. So when more than one warehouse exists, refuse every
  non-GET request and every Inertia visit that doesn't name the matching warehouse (missing = refused, not allowed through). That
  covers `router.post`, plain `fetch`, `sendBeacon` and `window.open`, not only axios. Also store the warehouse id in the session and
  compare it, and put the warehouse in download URLs (PDF/CSV links).
- **Browser storage and long-lived cookies (recommended).** On one host every warehouse shares `localStorage` and cookies.
  `PrintMenu.vue` stores `ri_print_last_<media>` as a **printer id**, and printer ids are numbered separately in each warehouse, so
  after switching, a user could get whatever printer has that id in the other warehouse picked by default. `pin_device_token` and
  `device_session` clash the same way: approving a shared tablet for training would replace its production trust cookie. Add the
  warehouse id to localStorage keys and to these cookie names. (The session cookie keeps one name on purpose, above.)
- **Chooser privacy.** A public dropdown would reveal which organizations share an instance. Each warehouse has a `listed` flag;
  unlisted ones are reached by hostname, `?w=`, or typing a code. Training is `listed` (but see the training switch, §12).
  **Recommended:** make display names unique on the instance and show the warehouse name clearly on the login page, so a
  look-alike warehouse reached through a `?w=` link is harder to mistake for the real one. Rate-limit the typed code, and give the
  same answer whether or not the code exists.
- **Generated links** (the `/join` QR code, `/vote`, driver portal, emails) always include the warehouse: host if mapped, else `?w=`.
  **Never build an emailed link from the request's `Host` header** (a forged Host would put the attacker's address in a reset
  email). Built in phase 2 as `Warehouse::publicUrl()` plus a URL-root switch task, *not* as a request-rejecting trusted-hosts check,
  which would break installs reached by IP address or `.local` — see §18.
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

**Recommended implementation details:**
- **Strip the prefix for routing only.** `TrustAuth` checks signatures against `$request->getRequestUri()`
  (`app/Http/Middleware/TrustAuth.php:129`), and the print client signs the full URL path including `/w/<id>` (`api.rs:116`).
  Signatures must still be checked against the *original* URI. Get it wrong one way and every client using a prefix is refused;
  get it wrong the other way and a signature made for one warehouse is accepted by another.
- **Tie `RIK-` credentials to the warehouse.** Today they are tied to the Host only (`RequestSigner::mintBearer`). The Launcher and
  print client keep one identity key across pairings, so a device paired with warehouses A and B on one host could replay A's
  credential against B. Built (phase 2) without a format change: the credential's audience is the host plus the `/w/<id>` prefix,
  so unprefixed credentials are unchanged; one vector was added to `federation-signing-vectors.json` (§18).
- **On a mapped host, refuse a `/w/<other>` prefix**, the same as `?w` (§3).
- **Unprefixed machine URLs go to warehouse 1, permanently.** Every device, peer and launcher paired today uses a URL with no
  prefix; adding a second warehouse must not break them. This is the one deliberate exception to "no warehouse known = error" (§9).
- **"Domain verified" needs its own hostname.** On a shared host, `DomainVerifier` only proves the hosting party controls the
  domain, not that this warehouse's organization does. Offer `domain_verified` only for a warehouse with its own mapped hostname,
  and word it differently otherwise.

## 5. Keys

- **`APP_KEY` stays global per instance** and protects only cookie/session transport (and the instance-admin step-up).
- **`WAREHOUSE_KEY`** — one per warehouse, in `/secrets/warehouses/<id>/warehouse.key` (§2) — is what `Crypt`, `encrypted` casts, signed
  URLs and password-reset links use inside a warehouse.
- **Middleware order:** `EncryptCookies` (APP_KEY) → `ResolveWarehouse` → swap to `WAREHOUSE_KEY` + warehouse connection →
  `StartSession`. This avoids the chicken-and-egg problem (a per-warehouse cookie key would need the warehouse before the cookie
  could be decrypted).
- **Benefit:** the key that matters travels with the warehouse — restoring or moving one needs only its `WAREHOUSE_KEY`. Losing
  `APP_KEY` merely logs everyone out. The "write down your key" notice becomes per warehouse.
- **Gotchas to test:** clear the `Crypt` facade instance and any static model encrypter after the swap; signed URLs must carry the
  warehouse (host or `?w`, inside the signed part); the queue worker swaps per job; `config/backup.php`'s `encrypted_columns`
  registry (test-enforced against model casts) now refers to `WAREHOUSE_KEY`.
- **Account-level links use `APP_KEY`, not `WAREHOUSE_KEY` (decided 2026-10-27).** A warehouse admin can
  view their `WAREHOUSE_KEY`, so anything signed with it can be forged by that admin. That's fine for links about the warehouse's
  own data, but not for links about an *account*, which is shared across warehouses. Concretely:
  - **Email verification** (`verification.verify`, a `signed` route) is signed with `APP_KEY`. Signed with `WAREHOUSE_KEY`, a
    warehouse admin could forge "this address is verified" for an account email they don't own.
  - **Password reset links aren't signed at all.** The link carries a random token, and the broker compares it with a hash stored
    in `password_reset_tokens`, so no key is involved. What matters is **where that table lives: in the accounts schema**. If it
    stayed in the warehouse schema, a warehouse admin could restore a dump containing a token hash they chose, for any email,
    and reset that account everywhere.
  - Any future account-level signed link (email change confirmation, account invitations) follows the same rule.
  
  `APP_KEY` is already instance-wide and never shown to a warehouse admin (the key reveal becomes per-`WAREHOUSE_KEY`), so no new
  key is needed. Losing `APP_KEY` still only logs everyone out and voids outstanding verification links.

## 6. Identity: central accounts, one login

Split *who you are* from *what you may do*.

- **`accounts`** (own schema/user, used only by the auth provider): login email, password hash, 2FA/OAuth fields (reserved), status,
  `is_instance_admin` — settable only in the instance console or CLI, never from a warehouse or an imported backup.
- **`people`** stays per warehouse (party record, roles, permission overrides, PIN, badge, approval/disabled state) with a nullable
  `people.account_id`. Only login-capable people have an account; donors, drivers and passwordless training trainees do not.
- **Recommended: `memberships` in the accounts schema is the only record of who belongs where** —
  `memberships (account_id, warehouse_id, person_id, status, is_training)`. `people.account_id` is at most a cached copy, checked
  against `memberships` and rebuilt from it after a restore; it is never read from a dump. Two reasons:
  - A warehouse admin can write any column in their own schema through a restore. PIN unlock, tap-login and kiosk logins don't
    check a password. If membership lived in `people.account_id`, an admin could point a person they can PIN into at the instance
    admin's account, and anything that trusted that link would treat them as that account.
  - The sole-membership rule (below) needs to see every warehouse's members. None of the per-warehouse DB users can, and the
    control user only reads `warehouses`, so without a central table nothing could actually compute it.
- A custom **user provider** verifies the account centrally, then resolves *this warehouse's* person through `memberships`. `Auth::user()` and
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
**Recommended for the support person:** it never gets an account, PIN or badge; it expires with the session; the escalation guards
(`assertNoEscalation` and friends) never treat it as someone to compare against; and it is never deleted afterwards, because records
name it as their actor. Show the warehouse's admins a notice when one is opened, not only an audit entry — hosted customers will
expect to be told.
Instance-only: software update, Docker/TLS settings, Reverb, slow log, mail server settings (§13), Turnstile keys, creating/suspending
warehouses, global backup. Everything else is warehouse admin.

**Step-up before anything instance-wide (decided 2026-10-27).** Being logged in to a warehouse never grants instance-admin powers
by itself. Before the instance console or any instance-wide operation, the person must **re-enter the account's email and
password**. The step-up expires after a short time, like Laravel's `password.confirm`. This applies however they logged in, and
**always** to a session that started from a PIN, tap-login, kiosk or support session, since none of those proved the account
password. A PIN is never accepted as the step-up. Whether someone is an instance admin is read from `accounts` at the moment of
the step-up, never from anything stored in a warehouse (`people.account_id` is writable by a restore; see `memberships` above).

### The sole-membership rule
One definition, used by backups *and* by admin powers. A warehouse is an account's **only home** if the account's memberships are in
that warehouse plus any number of **training warehouses** (`warehouses.is_training`; training memberships do not count). Only an
instance admin can set `is_training` (otherwise a warehouse admin could label a real warehouse "training" to keep powers over a
shared account). **Recommended:** computed from `memberships` only (above), never from `people.account_id`.

- **Backups:** a warehouse backup includes `accounts.json` for its members: password hashes always (key-independent); 2FA/OAuth
  secrets re-encrypted under that warehouse's `WAREHOUSE_KEY` (blanked on restore if the key isn't supplied). Credentials of an
  account that is **not** sole-membership are never exported (identity only → the user resets by email), so one warehouse's admin
  cannot harvest hashes of people who also belong elsewhere. "Someville Warehouse + Someville Training" exports fully.
- **Import** (a backup's `accounts.json`, and any people import that carries login emails) matches by email: new → create (never
  instance admin); existing → **keep what is already in the database, attach the membership, and list every difference on an
  import report** (imported name/phone/etc. vs. what was kept) for a human to look at. An import **never modifies an account that is
  linked to another warehouse**, whatever options a future import might offer.
  **Recommended: "attach the membership" means a pending invitation, not a membership.** The account's owner accepts it after
  logging in. Otherwise any warehouse admin could write an `accounts.json` by hand (or import people with login emails) and attach
  anyone's account, an instance admin's included, to their warehouse. That would change *the victim's* sole-membership status (their
  own warehouse admin loses the power to reset them, and their warehouse's backups stop carrying their credentials), reveal which
  emails have accounts, and set up a look-alike warehouse to lure them into. This brings the invite part of §16 forward.
  **Recommended for the Data Import "Names & addresses" importer** (it matches people by name + zip, not email, and today
  *updates* a match): use the same keep-and-report rule. A wrong name + zip match currently overwrites a real donor's address with
  no record of what was there. It concerns `people` rows, not accounts.
- **Admin powers:** a warehouse admin may reset the password of, or lock/unlock, an account only when that warehouse is its sole
  home. For an account with other real memberships the warehouse admin can only disable that person's *membership* in their own
  warehouse (local, reversible) and **send a password-reset email** — the same thing anyone can do from the login dialog, so it is
  not dangerous; the admin never sets or sees a password for it. An instance admin can reset/lock any account. Account lock is account-level (all warehouses), which
  is exactly why it needs the test. Powers drop at the moment an account gains a second real membership.
  **Recommended for the edge case** of an account whose only memberships are in training warehouses (no "real" home, so the rule
  as written gives no warehouse admin authority over it): its training warehouse's admins have the sole-home powers while that is
  its only membership, and instance admins always do.
- **Global backup** includes the whole accounts schema.

### Login, sessions and account changes (recommended)
- **Locking or resetting an account ends its sessions everywhere.** Sessions and remember-me tokens live in each warehouse's own
  database, so today an account-wide lock or password change in one warehouse would leave the account logged in to the others
  until those sessions expire. Add `accounts.auth_epoch`, bumped on lock, password change and reset; copy it into the session at
  login and compare on each request (the same idea as `trusts.session_epoch`). One indexed query; an APCu cache can absorb it later.
- **Login attempt limits are per account, not per warehouse.** The rate limiter keeps its counts in the cache, which is each
  warehouse's own database, so an attacker would get a fresh set of tries against one account in every warehouse. Keep the counts in
  the accounts schema. Give one identical answer (same wording, similar timing) for "wrong password", "no such account" and "not a
  member here"; checking the password and *then* saying "not a member" tells a guesser the password was right.
- **Email change:** changing the login email changes it for every warehouse. Require the current password and confirm the new
  address through the instance mailer (§13), with a link signed with `APP_KEY` (§5). When a warehouse admin edits a person, they
  change only the *contact* email (`people.email`), never `accounts.email`.
- **Account deletion** from the profile, for an account with other memberships, means "leave this warehouse"; deleting the account
  itself is only offered when this is its sole home.
- **Email matching** is case-insensitive: `accounts.email` gets a case-insensitive unique index.

### Removing a warehouse
Decided: when a warehouse's data is removed, **every account that is not multi-membership is removed with it** — the same
sole-membership test (training warehouses excluded), so an account that belonged only to the removed warehouse (and any training
warehouse) goes; an account that also belongs to another real warehouse just loses this membership and is untouched. Instance admin
accounts are never removed by this cascade. Removing a training warehouse never removes an account that has a real membership.
**Recommended mechanics:** instance admin only; the warehouse goes `archived` first and is removed from that state. **Decided
2026-10-28: no waiting period is forced.** The instance admin chooses when to remove an archived warehouse, and may do it at once
(archiving is the undoable step; removing is the instance admin's decision and the typed confirmation is the safeguard); a final warehouse backup is taken; typed confirmation naming the warehouse; the
schema, DB user, storage dir, `WAREHOUSE_KEY` and `warehouse_domains` rows go with it; the removal is audited in the instance log
(including which accounts were removed). Deleting the removed accounts' `memberships` rows also covers their training memberships
in one step; the training warehouses' `people.account_id` copies are cleared from it.

### Migration of existing installs
Copy each login-capable `people` row's email and password hash into `accounts`, add a `memberships` row, set `account_id`.
Single-warehouse installs see no visible change. **Recommended:** when a warehouse later moves in from another instance, anyone whose
email already has an account here keeps *this* instance's password (keep-what-is-there, above) — tell them before the move.

## 7. Database access

Three kinds of credential:

- **Provisioner** — create/drop schema and user, grants. Held only by the queue container (secret mounted only there); the web
  container never has it. Provisioning runs as a job.
  **Recommended:** run provisioning in its own small worker that reads a table of plain commands (`create`, `drop`, `reset-training`
  plus a warehouse id), not serialized Laravel jobs. The main queue worker un-serializes PHP objects from a table the web
  container can write to; if that worker also held the provisioner credential, a forged job row would be a way from the web
  container to the provisioner. The provisioning worker re-checks each command against the control schema (e.g. `reset-training`
  only for an `is_training` warehouse) rather than trusting what the command says.
- **Control** — read-only on the `warehouses` schema (plus table-level write on the queue tables, §9). No secrets in the control DB.
- **Per-warehouse user** `ri_<id>` — on its own schema only. Password is a random file next to the key; owned by
  `Warehouse::connectionConfig()`, so provisioning creates/drops the user and migrations/restore run as that user.
  **Recommended grants:** an explicit list, not ALL: `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES,
  CREATE TEMPORARY TABLES, LOCK TABLES`. Leave out `TRIGGER`, `EVENT`, `CREATE ROUTINE` and `CREATE VIEW` unless the app starts
  using them (then also stop dumping with `--routines --triggers`, and refuse dumps containing `DEFINER`); never `FILE` or `SUPER`.
  Set `max_user_connections` per user so one warehouse can't take every connection.

**Why per-warehouse users (recommended, not optional):** restore executes SQL from an uploaded zip (`RestoreService` /
`DatabaseTools`). With a single pattern-granted user, a crafted dump could `USE`, read or drop another warehouse; with per-warehouse
users it is confined to its own schema, and the grant list (no FILE, no GRANT, no CREATE USER, no TRIGGER/VIEW/ROUTINE) covers the rest.

**Restore security model (decided 2026-10-28, built):** two layers, and nothing else.
1. *What the SQL can do* is limited by the database user's grants, nothing more. The dump is **not parsed or filtered** (no scan for
   `USE`, `GRANT`, `DEFINER`...): a keyword filter would be a weak layer next to the grants, and whatever it let through the server
   refuses anyway.
2. *What the client program can do.* `mariadb` runs inside the app container and has `system`/`\!`, `source`, `tee`/`pager` and
   `LOAD DATA LOCAL INFILE`, none of which the grants touch. `DatabaseTools::clientCommand()` therefore passes **`--sandbox`** (no
   client command that touches the file system) and **`--local-infile=0`**, and requires the `mariadb` client (the image has it;
   MySQL's client has no `--sandbox`). `RestoreClientSandboxTest` runs hostile SQL through the real client.
A dump's content is still *its warehouse's own data*: a bad restore can wreck that warehouse and nothing else (the compatibility check
on the manifest's migration list, and the pre-restore backup, are the protection against mistakes). The same
property makes a wrong-connection bug or an SQL injection fail closed. The accounts connection is used only by the login code with
parameterised queries, so an injection in a normal warehouse query (running as the warehouse user) cannot reach it.

**What per-warehouse users don't protect against (recommended to state plainly):** the web container has to read every
warehouse's password file to serve it, so if the web container itself is broken into, every warehouse is exposed. Per-warehouse
users stop SQL-level mistakes and attacks (injection, a query on the wrong connection, a crafted dump); they are not a boundary
against code running in the web container.

**Two connections per request are fine.** They are separate lazily opened Laravel connections on the Docker network (~ms); nothing
"reconnects". A request does one indexed query on the control connection (hostname → id, status) and then connects as the warehouse
user. Credentials come from files, so a read-only control user can never see a secret. No in-memory cache initially (FPM has none
anyway, and staleness would delay maintenance/suspend). If it ever shows in profiling, add a tiny APCu cache with a short TTL and
explicit invalidation on status change. A selector cookie or `?w` already names the id, so only hostname mapping needs the lookup.

**Provisioning is crash-safe and idempotent:** row `provisioning` → create schema + user → migrate → seed essentials
(`FirstRunService`) → write keys → issue a setup code → mark `awaiting_setup` last. No administrator yet: that is the setup
code's job (below).

### New warehouses: setup codes (decided in outline 2026-10-27)

Only an instance admin creates a warehouse (self-service creation stays a non-goal). The new warehouse is handed to whoever will
run it with a **one-time setup code**:

1. The instance admin creates the warehouse (display name, optional hostname, a contact email). Provisioning runs as above and
   the warehouse ends in `awaiting_setup`.
2. The console shows the warehouse's address and a **setup code**, and can email both to the contact through the instance
   mailer (§13). The instance admin can also pass them on by hand (phone, a customer portal).
3. At the warehouse's address, an `awaiting_setup` warehouse shows only "enter your setup code". The code holder then either:
   - **Sets up a fresh warehouse:** logs in with an existing account, or creates one, and becomes its Administrator. Optional
     example data, as in today's first-run wizard.
   - **Restores a backup into it:** the existing restore wizard (`RestoreController`). Any backup is allowed, since the warehouse is
     empty; it runs as the warehouse's own DB user with `--sandbox` (§7), and its `accounts.json` produces invitations, never
     memberships or credential changes (§6). The code holder becomes its Administrator either way.
4. The warehouse goes `active`, and the code is used up.

**Recommended details:**
- The code is single-use and tied to one warehouse. It expires after 7 days. Only its hash is stored, in the control schema. The
  instance admin can revoke it or issue a new one. Code entry is rate-limited. The format is the same as a pairing code
  (`ABCDE-FGHJK`).
- Holding a code gives power over that one empty warehouse only, never instance-admin powers. `is_instance_admin` is never set by
  this path, and never imported from a backup.
- Provisioning sets `setup_state.completed_at`, so `RedirectToSetup` and today's `/install` wizard never run inside a provisioned
  warehouse. The setup-code screen replaces them. (Otherwise the first stranger to reach a new warehouse would become its admin.)
- `RI_SETUP_CODE` and the `/install` wizard stay for the *instance's* first run only: they create the first account (instance admin
  and warehouse-1 Administrator) and warehouse 1.
- The instance admin can see which warehouses are still `awaiting_setup`, and when each code expires.

## 8. Resolution is hand-rolled, not `spatie/laravel-multitenancy`

Decision: write a small `WarehouseContext` rather than adopting the package. (Not checked against Laravel 13; verify support if this
is reconsidered.) The package would give tenant-aware queued jobs and an artisan looper — roughly 100 lines to write. Our hard parts
are all custom: host/`?w`/cookie/header/URL-prefix resolution, `WAREHOUSE_KEY` and the cookie ordering, credentials from files,
hold-all upgrades, status gating, backups. Mirror its vocabulary (a list of "switch warehouse" tasks: connection, key, storage, cache
prefix, session) so adopting it later stays possible. **Recommended additional switch tasks:** the URL root (the warehouse's primary
host, or `APP_URL` + `?w`, §3) and the log context (`Log::withContext(['warehouse' => …])`, §13).

## 9. Background processes: one process, many warehouses

Compose can't add services dynamically, so there is no process per warehouse.

- **Queue:** keep one stock `queue:work`. Jobs and failed jobs live in the control schema (separate table-level grants); the payload
  carries the warehouse id (`Queue::createPayloadUsing`); a `JobProcessing` listener bootstraps and tears down the warehouse
  (connection, key, storage, cache prefix). FIFO across warehouses, no looping worker. A restore purges that warehouse's pending jobs.
  **Recommended:** a job with no warehouse id fails; it is never run against warehouse 1 by default. The same goes for broadcasts and
  anything else that runs outside a request: if no warehouse is known, raise an error. (The one deliberate exception is
  unprefixed machine URLs, §4.) Use `ShouldBeEncrypted` for jobs that carry personal data: `failed_jobs` sits in the control schema
  and would otherwise collect serialized personal data from every warehouse, which then lands in the global backup.
- **Scheduler:** one loop running each scheduled command for every active warehouse (`warehouse:each <command>`).
- **Reverb:** channel names prefixed with the warehouse id, or `print-queue` and friends would leak across warehouses.
  **Recommended:** the channel authorisation in `routes/channels.php` also checks that the prefix is the current warehouse; a prefix
  alone doesn't stop a signed-in user from asking for another warehouse's channel.
- Long-lived processes must always reset warehouse state between jobs.

## 10. Maintenance and upgrades

- Per-warehouse `status`: `provisioning | awaiting_setup | active | maintenance | restoring | upgrading | held | suspended | archived`, enforced by
  middleware. Laravel's global `php artisan down` is no longer used for restores or training reset.
- **Upgrade failure = hold all.** The `web` entrypoint migrates every warehouse and records per-warehouse `migration_state` and the
  pre-upgrade backup id (a pre-upgrade backup of every warehouse is automatic). If any fails, *all* are held. The instance admin sees
  what failed and may **explicitly unblock the non-failed ones**. The UI must say that unblocking is a commit point: code can no
  longer be rolled back for those warehouses. MariaDB DDL isn't transactional, so a failed warehouse may be half-migrated; recovery is
  restoring its pre-upgrade backup (§11), which touches only that warehouse. Reason for hold-all: a failed upgrade may need a restore,
  which is complicated if other warehouses have since been entering new data.
- **Recommended corrections to the recovery story:**
  - Code is shared by the whole instance, so there's no rolling back code for *one* warehouse. A failed warehouse restored from its
    pre-upgrade backup is back on the old schema, and the new code will try the same migration and fail the same way. The real
    choices are: **fix forward** with a patch release (the failed warehouse stays held until then), or **roll back the image and
    restore every warehouse** (losing anything entered in warehouses that were unblocked). The unblock screen should say exactly
    that.
  - There are three sets of migrations: control, accounts and warehouse. Each needs its own credential (the per-warehouse users
    can't touch the other two schemas). Run control and accounts first, then the warehouses. A failure in control or accounts holds
    the whole instance.
  - Migrating N warehouses at start-up delays the web container. Raise its healthcheck grace period, or have it serve a "being
    upgraded" page while migrations run.
  - A pre-upgrade backup of every warehouse multiplies upgrade time and disk use by N. Check free space before starting, and refuse
    to start if it isn't enough.

## 11. Backups and restore

- **Warehouse backups** (taken by that warehouse's admin; scope database | full | storage) are schema-name-independent (no
  `CREATE DATABASE`/`USE`), carry warehouse id + uuid + migration list + SHA-256s + `accounts.json` (§6), and **never contain keys**
  (existing rule).
- **Restore into one warehouse, touching nothing else:** set it `restoring` → load the dump as its own DB user (client run with
  `--sandbox`, §7), wiping only its
  tables → replace only its storage dir → keep its own `WAREHOUSE_KEY` (encrypted columns from a different key are blanked, as
  today) → purge its queued jobs → back to `active`. A warehouse admin may restore only backups of the same lineage uuid; restoring
  another warehouse's backup into it (clone/migrate) is instance-admin only. "Create warehouse from backup" extends the first-run
  restore wizard (`RestoreController`).
- **Recommended: leave live-state tables out of warehouse dumps** — `sessions`, `cache`, `cache_locks`, `password_reset_tokens`
  (which moves to the accounts schema anyway, §5) and anything left of `jobs`. Otherwise a warehouse cloned from a backup starts with
  working copies of the original's logged-in sessions. Empty them on restore as well, for older backups.
  *How:* `DatabaseTools::dumpCommand()` adds `--ignore-table-data=<schema>.<table>` for each one (supported by the image's MariaDB
  11.8 `mariadb-dump`). That keeps the `CREATE TABLE` and skips the rows, so a restored warehouse still has every table. Don't use
  `--ignore-table`, which drops the structure too: `RestoreService` runs `db:wipe` before loading, so the tables would be missing
  until a migration happened to recreate them, and it wouldn't. After the load, `RestoreService` truncates the same tables, which
  covers backups made before this change. Keep the list in one place (`config/backup.php`, next to `encrypted_columns`), with a test
  that every table on it exists. This doesn't depend on multiple warehouses and could ship on its own today: restoring a backup on
  the same instance currently brings back the sessions that were live when it was taken.
- **Recommended: the lineage uuid and SHA-256s prove nothing about origin.** They come from the zip's own manifest, so whoever made
  the zip chose them. They catch mistakes (wrong file, damaged download); what keeps a crafted backup contained is the warehouse's
  own DB user and `--sandbox` (§7), and the invitation rule for accounts (§6).
- **Global backup** (instance admin): control schema dump + accounts + every warehouse + their storage dirs. Keys are listed/exported
  only under the existing password-confirm + audit rule (`view-app-key` equivalent, per warehouse). The control schema is the map —
  losing it means every warehouse must be re-registered from its own backup manifest.

### Files per warehouse (recommended, 2026-10-27)

How uploads and other files are kept apart, and how a warehouse's backup carries only its own files.

**What makes this easy:** every uploaded file today goes through the `local` disk (`Storage::disk('local')` or `->store(…, 'local')`):
receiving photos, signed BOLs, feedback screenshots, imports, training signatures and archives, chunked uploads, device releases.
The database stores paths *relative to the disk root* (`receiving-photos/abc.jpg`). Nothing uses the `public` disk. So:
- **Switching the disk is a switch-warehouse task (§8).** Set `filesystems.disks.local.root` to `<storage>/warehouses/<id>/files/`
  and call `Storage::forgetDisk('local')`, because `FilesystemManager` caches disk instances, like the `Crypt` gotcha in §5. Every
  stored path stays valid unchanged, and controllers don't change. The queue worker does the same per job (§9).
- **One folder per warehouse, one meaning:** `<storage>/warehouses/<id>/files/` holds exactly that warehouse's files and nothing
  else. A **full** warehouse backup is that warehouse's database plus that folder; a **storage** backup is the folder alone; a
  restore replaces only that folder (§11). Backup zips keep their `files/private/…` entries, so the format doesn't change.
- **Drop the `public` disk** and the `public/storage` symlink (made in the `Dockerfile`). One web path can't serve many warehouses'
  public files without mixing them, and nothing uses it. Any future public file is served through a controller that checks the
  warehouse.

**What lives elsewhere, and why:**

| Kind | Where | Why not in the warehouse folder |
|---|---|---|
| Secrets (`warehouse.key`, DB password) | `/secrets/warehouses/<id>/` (§2) | Must never be in a backup or reachable by a file route |
| That warehouse's backup zips | `<backup dir>/warehouses/<id>/` | Otherwise every full backup would include all earlier backups, and restoring files would delete them |
| Restore and backup status files | next to that warehouse's backups | Must survive the restore that replaces the files folder |
| Logs, slow log, update status, `backup-settings.conf`, compiled views | instance `storage/` as today | Instance-wide (§13); never in a warehouse backup |
| Simulator status (`simulator-status.json`) | the warehouse's files folder | Per warehouse; today hard-coded with `storage_path()`, so it moves to the helper |

**Things to settle while building it:**
- **The training class archive** (`training-archives/`, on the `local` disk) has to survive a training reset, which drops and
  re-provisions that warehouse (§12). Either the reset keeps the files folder's `training-archives/` (the simplest), or archives
  live beside the warehouse's backups.
- **Device releases** (R Station OS images, `device-releases/`) are large. Per warehouse is consistent with devices being per
  warehouse, but each warehouse holding its own copy multiplies disk use, and full backups get large. Consider leaving them out of
  backups (they can be uploaded again), or making releases instance-wide later.
- **There's no operating-system separation between warehouses' files:** one PHP process serves every warehouse, so the separation
  is only as good as the app's path handling. The guard test (§13) must also fail on `storage_path('app…')` and absolute paths
  outside the helper, and every route that serves a file by a stored path must refuse `..` and absolute paths. (The secrets are
  outside the tree, so a traversal bug can expose another warehouse's uploads but not its keys.)
- **Migration (§15):** `storage/app/private/*` moves to `<storage>/warehouses/<warehouse-1 id>/files/`, and `storage/backups` moves
  to `<backup dir>/warehouses/<id>/`. The stored relative paths don't change.

## 12. Training as a standard warehouse

**Training warehouses are added on request, not provisioned automatically (decided 2026-10-27).** A new instance gets only its
default warehouse (warehouse 1). The instance admin can add a standard **training** warehouse at any time, in one step from the
instance console: it's provisioned like any other warehouse, seeded with dummy data, and marked `is_training`, listed, and reachable
on a single hostname. An install that never runs a class never pays for one in disk, memory or upgrade time (this matters on an R
Station). Practice data stops mixing with real data. Because training memberships don't count for the sole-membership rule, the same people can belong to both a real and a
training warehouse without losing their backup or admin-reset behaviour.

**Decided 2026-10-27: in-warehouse Training Mode is deprecated and its code removed.** Training is simply a warehouse. Concretely that
retires the machinery that existed to make a *real* database behave like a classroom: the on/off switch *as a mode of a live
warehouse* (the mode flag on `training_settings` — the row itself stays for event title/location and the prepared summary; the
switch itself survives in a new form, below), the
permission cap on a training login (`TrainingMode::TRAINING_KEYS` intersected in `HasPermissions`; the Trainee role still limits
what a trainee can do, and the warehouse is disposable), the "reset point" database backup
taken when the mode is switched on, `TrainingReset` / `training:reset` (a whole-database restore in maintenance mode), and the
`CheckPermission` training-specific responses. Nothing about a class should be able to put a production database into maintenance mode.

- **Seeding:** a training warehouse is provisioned from a seed — dummy data (the existing `WarehouseActivitySeeder` / simulator
  material). Seeding from a real warehouse's own data is part of the later feature below.
- **To decide later: warehouse admins deploying their own training warehouses.** A warehouse admin might want a customized
  training warehouse tied to their own real warehouse, seeded from its catalog, locations and other data, so a class practises on
  familiar items. This is probably wanted, but it should be built later, after the standard training warehouse works. Questions to
  settle then:
  - **Personal data:** a copy of real data must be sanitised. People, donors, partners, contact details and uploaded files are
    personal data, so the safe default is catalog/items/locations only (or names scrambled).
  - **Creation:** warehouse admins can't create warehouses today (instance admin only, §7), so this needs either a request the
    instance admin approves, or an allowance the instance admin grants to that warehouse.
  - **Ownership and authority:** whether the real warehouse's admins administer its training warehouse, and how the sole-membership
    rule (§6) treats it.
  - **Shared-resource limits:** how many training warehouses a warehouse may have, since there are no quotas yet (§1 trust model).
- **Reset is deliberately simple and local:** drop and re-provision *that one warehouse* from its seed (no dump/restore, no pre-reset
  backup, no maintenance mode for anyone else). It touches nothing outside the training warehouse and is the same code path as
  provisioning, so it needs no separate machinery.
- **Keeping members across a reset (recommended):** the member list already lives outside the warehouse, in `memberships` (§6). A
  reset deletes the person rows with everything else and re-creates them, with their roles, from `memberships`. To make that
  possible, `memberships` also records each member's role bundle for training warehouses.
- **Class features that survive inside the training warehouse (decided 2026-10-27):** trainee tap-login and self sign-up
  (`/training-login`, `/join`), report cards and certificates (and the always-on activity log they read from), training products
  with Prepare Training (and its setup packet, W-pallet trays and table-spot labels), and the team sheets / station guides. They are
  gated by "this warehouse `is_training`" instead of the removed mode switch, and they exist only in training warehouses. Consequences:
  tap-login trainees stay passwordless people with no account (`is_trainee`, `account_id` null) and the placeholder
  `trainee-<id>@training.invalid` emails can go (the login-capable test becomes "has an account, or is a trainee"); a reset wipes the
  class data (trainees, sign-ups, practice records, activity log), so the existing **class archive** (sign-ups CSV, report cards,
  certificates, written to files outside the schema before a reset) is kept unless it proves to complicate the simple reset.
  **Recommended:** keep the class archive — it's only files, written before the reset, and doesn't make the reset any harder.
- **Training switch (decided 2026-10-27).** The Training Mode switch stays, but all it does is **enable or
  disable the training warehouse**. It never changes how a live warehouse behaves. Disabled, the training warehouse refuses
  everything: logins (password, PIN, tap), `/join`, `/training-login` and its machine URLs. Its data is kept, and a disabled
  warehouse is left out of the chooser. Enabled, it works as above. This keeps today's protection: an always-on, listed training
  warehouse would offer password-free tap-login and collect `/join` sign-ups (real attendees' contact details) to anyone who can
  reach the instance, all year round.
  **Recommended details:** the training warehouse's admins (`manage-training`) and instance admins may flip it. Tap-login still
  needs an approved trusted device, as today. The training login page is offered as today: an approved trusted device's `/login`
  goes to `/training-login`, and everyone else gets the training warehouse's normal login page.

## 13. Other seams (carry-over checklist)

- Hard-coded single-DB spots: `DatabaseTools`, `BackupService`, `SlowQueryLog`, `SetupReset`, `RestoreService`, `config/backup.php`,
  `docker/entrypoint.sh` (DB wait, first-run detection), `docker/db-backup.sh`, `docker/scheduler.sh`.
- Every `storage_path()` / `Storage::disk()` caller (receiving photos, driver portal, print PDFs, feedback screenshots, chunked
  uploads, simulator/training status files) → one `Warehouse::storagePath()`, plus a **guard test** that fails on a literal
  connection name or a raw storage path outside the helper. How the `local` disk is switched, and what lives outside the warehouse
  folder: §11, "Files per warehouse".
- Per warehouse: banner, kiosk settings, printers/devices, trusts and federation identity, backups, domains. Instance only:
  software update, Docker/TLS, Reverb, slow log, **mail server settings**, Turnstile keys.
- **Mail settings are instance-wide (decided 2026-10-27).** `MailSetting` (mailer, From, Resend key, SMTP
  host/port/user/password, feedback recipients) moves to the control schema, and `/setup/email` moves to the instance console.
  Reason: password-reset and verification emails are account-level. If a warehouse admin could point their warehouse's mailer at
  their own server, they could request a reset for any email address and read the link. With one instance mailer, the reset email
  a warehouse admin may send for a shared account (§6) really is harmless. The two encrypted secrets are then encrypted with
  `APP_KEY` (instance), not a `WAREHOUSE_KEY`, and are part of the global backup, not warehouse backups.
- Warehouse simulator and `setup:*` commands run against a chosen warehouse (`--warehouse`), default warehouse 1.
  **Recommended:** the default applies only while there is one warehouse. With more than one, destructive commands (`setup:reset`,
  the simulator, `backup:restore`) refuse to run without an explicit `--warehouse`.
- **Instance console (to design during implementation).** Several things in this plan move to it: mail settings, Turnstile keys,
  software updates, Docker/TLS, Reverb, the slow log and other logs, per-warehouse disk use, creating warehouses and issuing setup
  codes, suspending, archiving and removing them, global backups, and support sessions. Still to design: its pages, how it is reached
  (§6's step-up), and what it looks like on a single-warehouse install, where today these settings sit on warehouse pages
  (`SystemAdmin.vue`, `/setup/email`, `/setup/testing`).
- **Test setup (problem to solve during implementation).** `scripts/TESTING.md` assumes one disposable database and one user, and
  `RefreshDatabase` works on one connection. Multi-warehouse tests need several warehouse schemas plus the control and accounts
  schemas, a per-warehouse DB user and a test provisioner, and they must keep the "never the real database" guarantee. Without
  that, the tests this plan relies on can't be written: the guard test against a literal connection name, a query on the wrong
  connection failing, a restore that can't reach another schema. Solved in phase 2: `tests/SecondWarehouse.php` (§18).
- No quotas initially; size `table_open_cache` for N × ~100+ tables. **Recommended:** even without quotas, show disk use per
  warehouse in the instance console (one warehouse's photos and backups fill the shared disk for everyone), and make "one backup at
  a time" instance-wide (one `flock` file), so N warehouses' scheduled backups don't all hit the database at once.
- **Turnstile (recommended):** its keys are instance-wide, but each Cloudflare widget lists the hostnames it accepts. Mapping a
  new hostname must include adding it there, or `/join`, `/vote` and registration fail on that host. Put it in the domain-mapping
  steps and warn when a mapped host isn't confirmed.
- **Logs (recommended):** `laravel.log` and the slow-query log mix every warehouse's data, so any page that shows them belongs in
  the instance console (today `/setup/testing` is `admin-system`, a warehouse permission). Every log line carries the warehouse id
  (a switch task, §8), which also helps debugging.
- **Feedback reports (recommended):** record the warehouse id as well as the host, so central can tell two warehouses on one
  host apart.

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
| 6 | Profile: email change, account delete (see §6, "Login, sessions and account changes") | S |
| 7 | CLI + first run: `FirstRunService`, `admin:bootstrap`, `user:create` (hard-coded role id 15), `user:promote`, `user:deactivate`, password command, `SetupController` | M |
| 8 | PIN / kiosk / trusted devices (mostly unchanged — person id preserved) | S |
| 9 | Training login, preparer, signup (drop placeholder emails, keep the session key) | S |
| 10 | Sessions (`sessions.user_id` must stay the person id; deactivate does not end existing sessions today — recommended: `accounts.auth_epoch`, §6) | S |
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

### Moving existing instances to the new model (decided 2026-10-27)

There are **no production deployments yet**, only test instances (demo, cs26 and similar). So the goal is an in-place upgrade with
no manual steps and no long-lived compatibility code for the old layout. Logging everyone out once is acceptable. Recommended
mechanics:
- **Schema:** the existing schema keeps its name (`reliefinventory`; the name is stored, §2) and is registered as warehouse 1.
- **Keys:** the old `APP_KEY` becomes warehouse 1's `WAREHOUSE_KEY`, so every encrypted column (the federation private key above
  all) keeps working. A **new `APP_KEY`** is generated, because the old one has been visible to warehouse 1's admins, and after the
  split `APP_KEY` signs account-level links (§5). The mail secrets are decrypted with the old key and encrypted again with the new
  one as they move to the control schema (§13). Everyone is logged out once.
- **Files:** moved into the standard layout (`<storage>/warehouses/<id>/files/`, plus the secrets tree, §2). Warehouse 1 gets no
  special-case paths.
- **Accounts:** created only for people with a password hash, or who are part-way through registration or an invitation. Never for
  `is_trainee` people (their `trainee-…@training.invalid` emails would otherwise become logins), and never for people who only have
  a contact email (donors, partners). The migration writes a report of who got an account. Check it on each test instance.
- **Training data on test instances** (e.g. cs26): stays in warehouse 1 as ordinary data. The old mode flag is cleared. No training
  warehouse is created; the instance admin adds one if it's wanted (§12). A test instance can be reset afterwards, if wanted, by restoring a backup.
- **Paired devices and peers** keep working unchanged through unprefixed machine URLs (§4). Nothing needs re-pairing.
- **Old backups** (taken before this change) can be restored into warehouse 1 or into a new warehouse. They are just an older
  schema, so the normal post-restore migrations bring them up to date. The step that turns their login people into accounts has
  to run outside the warehouse's own migrations (the warehouse DB user can't reach the accounts schema), and it follows §6's
  invitation rule.

0. **Invisible refactor:** `Warehouse`, `warehouse_domains`, `WarehouseContext`; the existing database becomes warehouse 1; single
   mode is a no-op, the full test suite is unchanged.
   **0b. Accounts layer** (can ship before any second warehouse exists): `accounts`, `memberships`, `people.account_id`, provider,
   migration of existing logins, `password_reset_tokens` in the accounts schema, `auth_epoch`, account-level login throttling,
   `UserFactory`.
1. Per-warehouse storage dir, secrets tree and `WAREHOUSE_KEY` (warehouse 1 moved to the standard layout, with its old `APP_KEY` as
   `WAREHOUSE_KEY` and a new `APP_KEY`; see above); `storagePath()` helper + guard tests.
2. Resolution (hostname / `?w` / cookie / header / machine URL prefix), key swap, queue / scheduler / Reverb, trusted hosts and URL
   root, the fail-closed tab guard, per-warehouse localStorage keys and device cookies.
3. Provisioning (including per-warehouse DB users), setup codes and the setup/restore screen for new warehouses (§7), chooser, domain mapping, the training warehouse (seeded; simple local reset) and
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
- Warehouse admins deploying their own customized training warehouses, tied to and seeded from their real warehouse (§12, to
  decide later).

## 17. Open questions

Resolved 2026-10-27 (kept here for the record): a hostname per warehouse is recommended and single-hostname/IP multi-warehouse is
fully supported (§3); in-warehouse Training Mode is removed and training is a seeded warehouse with a simple local reset (§12); a
warehouse admin may send a reset email for a multi-membership account but not reset the password (§6); imports keep existing details
and report differences, and never touch accounts linked to other warehouses (§6); removing a warehouse removes its
non-multi-membership accounts (§6).

Also resolved 2026-10-27, after a security review: restores run as the warehouse's own DB user with the client in `--sandbox` (§7);
instance-wide operations always need the account password re-entered, never a PIN (§6); mail server settings are instance-wide
(§13); account-level signed links use `APP_KEY`, and password reset tokens live in the accounts schema (§5); the training switch
stays, as enable/disable of the training warehouse (§12).

Also decided 2026-10-27: the trust model (§1); every Docker-image install gets multiple warehouses, including the Launcher
and the R Station local server, and only bare metal doesn't (§1); new warehouses are handed over with a one-time setup code (§7, outline decided,
details recommended); existing test instances are upgraded in place (§15).

Also decided 2026-10-27: a training warehouse is added on request by the instance admin, never automatically; only the default
warehouse is provisioned (§12). To decide later: warehouse admins deploying their own training warehouses (§12).

Still to design during implementation (problems stated, no design yet): the instance console and the multi-warehouse test setup
(§13).

Recommended but not yet confirmed (each is written into its section, marked **Recommended**): the questions that were open here —
who flips the training switch and how its login page is offered, keeping the class archive (§12); keeping training members across a
reset via `memberships` (§12); authority over training-only accounts (§6); keep-and-report for the Names & addresses importer (§6);
removal mechanics (§6) — plus everything else the review raised: the `memberships` table, invitation-only account attachment,
`auth_epoch`, per-account login limits, email change and account deletion (§6); a separate secrets tree (§2); the fail-closed tab
guard, per-warehouse browser storage and device cookies, trusted hosts, look-alike names (§3); machine URL details and domain
verification (§4); a separate provisioning worker, narrower DB grants, setup-wizard latch for new warehouses (§7); no
default-to-warehouse-1, encrypted jobs, Reverb channel checks (§9); the upgrade recovery corrections (§10); live-state tables out of
dumps (§11); Turnstile hostnames, logs, disk use, feedback reports (§13).

## 18. Implementation progress

Decided while building (2026-10-27):
- **One code path, separate schemas everywhere.** The control and accounts schemas are always their own schemas, named after the
  default warehouse's (`<DB_DATABASE>_control`, `<DB_DATABASE>_accounts`; `RI_CONTROL_DATABASE` / `RI_ACCOUNTS_DATABASE` override),
  so instances sharing a database server don't collide. Bare metal is slated for deprecation: it gets nothing beyond the two schemas
  (created by hand once, `BUILDING.md`) and stays single-warehouse. Don't build or maintain elaborate bare-metal paths; the bare-metal
  backup system (`scripts/update.sh` tiers) is to be reviewed and possibly deleted in favour of one Docker-focused backup path.
- **Naming:** the hosted-warehouse model is `App\Models\Control\Warehouse` (control schema). `App\Models\Warehouse` is still the
  physical building that locations belong to, inside each warehouse's schema.
- **Tests** use `<test db>_control` / `_accounts` (`tests/InstanceSchemas.php`, one-time grant in `scripts/TESTING.md`).

**Phase 0 — done.** `control` / `accounts` connections (`config/database.php`); control migrations in `database/migrations/control/`
(`warehouses`, `warehouse_domains`); `php artisan instance:migrate` (control → accounts → every warehouse; registers the existing
schema as the default warehouse, `warehouses.is_default`) replaces `migrate` in the entrypoint, `update.sh` and `dev-update.sh`;
the provisioner creates the two schemas on Docker with the root secret (interim, until the provisioning worker);
`WarehouseContext` + `SwitchTask`; the global `ResolveWarehouse` middleware (default warehouse only). It switches at the start of a
request and never undoes it on terminate, so terminable middleware still see the request's warehouse; switching to a *different*
warehouse resets everything the previous one set (worker-mode safe), (phase 2 decided a refused resolution needn't `forget()`, §18).

**Phase 0b — done** (decisions 2026-10-27: Administrator role holders become instance admins at migration; `people.password` and
`people.email_verified_at` are dropped; `people.email` always mirrors the login email and keeps its unique index; backups carry a
minimal `accounts.json` now rather than in phase 4). Accounts schema (`database/migrations/accounts/`): `accounts`, `memberships`,
`password_reset_tokens`, and `cache`/`cache_locks` for the `accounts` cache store (per-account login throttling). The warehouse
migration `move_logins_to_accounts` makes an account for everyone with a password or a verified email (never trainees, never
contact-only donors/partners) and writes a report to `storage/logs/accounts-migration-<schema>-<time>.log`.
- `Concerns\HasAccount` (Person and User): `password` / `email_verified_at` are virtual attributes backed by the account, written on
  `save()` (an override, not a model event). A new User, or a person given credentials, gets an account and a membership. A signed-in
  user changing another person's login (password, verification, email) is refused unless this warehouse is its sole home; the owner, a
  reset-token or verify link, and the CLI aren't limited. Deleting a person, or "delete my account", leaves this warehouse; the login is
  deleted only when no membership is left (never an instance admin's).
- `AccountUserProvider`: account by email → active membership here → person, and the person's `account_id` must agree. No account, not a
  member, locked and wrong password give the same answer and each cost one hash.
- `auth_epoch`: bumped on password change/reset and lock; the session stores `[account id, epoch]` at login, and
  `EnsureAccountSessionCurrent` logs out a stale one. Changing your own password re-records it, so only *other* sessions end.
- Console commands, migrations and tests use the default warehouse while there is exactly one (`WarehouseContext::current()`).
- `account:instance-admin <email> [--revoke]`; `user:create`, `admin:bootstrap` and the wizard's administrator are instance admins.
- Backups: `database/accounts.json` (`WarehouseAccounts::export()`: this warehouse's logins; password hashes only for sole-home
  accounts; never the instance-admin flag). A restore forgets this warehouse's memberships, migrates, then relinks from accounts.json (or,
  for an older backup, by email): existing accounts are kept as they are, missing ones created, orphans removed, and if the instance has
  no instance admin the restored Administrators become instance admins. `setup:reset` clears this warehouse's logins.

**Still open from 0b, for later phases:**
- A restore by a warehouse admin (phase 4) is untrusted: accounts.json and an old dump's password columns must produce *invitations*,
  never memberships or instance admins (TODOs in `WarehouseAccounts` and the `move_logins_to_accounts` migration).
- Self-service email change should need the current password and a confirmation link signed with `APP_KEY` (§6); today the profile
  page changes it directly (only possible while the account's sole home is here — the HasAccount guard refuses otherwise for others,
  but lets the owner through).
- Account lock has no UI yet (instance console, phase 5); the column, the login refusal and the session end are in place.
- `setup:reset` with several warehouses must not remove an instance admin who still runs the instance (TODO in `WarehouseAccounts::forgetAll`).

**Phase 1 — done** (decisions 2026-10-27: Docker keeps warehouse keys in a new `warehouse-secrets` volume at `/warehouse-secrets/<id>/`,
writable only by the app container, rather than under the read-only, init-owned `/secrets`, since Docker can't create a mount
point inside a read-only volume; bare metal writes the key file under `<repo>/secrets/warehouses/` from its current APP_KEY and
never rotates APP_KEY).
- `config/warehouses.php`: `storage_dir` (`storage/warehouses`) and `secrets_dir` (`RI_WAREHOUSE_SECRETS_DIR`); `Control\Warehouse`
  has `storagePath()`, `secretsPath()`, `key()` / `writeKey()` (never replaces a key) and a `connectionConfig()` that uses a
  `db_password` file next to the key when there is one (phase 3 makes them).
- Switch tasks: `SwitchDatabase`, `SwitchKey`, `SwitchStorage` (the `local` disk's root, and forgets the cached disk).
  `switchTo()` is all-or-nothing and puts the caller back where it was if a task fails. Console commands and the queue worker
  start in the sole warehouse (`bootConsole()`; skipped in tests, where `tests/InstanceSchemas` switches after a parallel worker's
  databases are chosen).
- **How §5 is implemented (differs from the middleware-order sketch above):** `app.key` and the container's encrypter are *never*
  switched — they stay APP_KEY, so cookies, the CSRF token, encrypted queue jobs and the framework's test helpers need nothing
  special. `SwitchKey` puts the warehouse's *data* under WAREHOUSE_KEY instead: `encrypted` casts (`Model::encryptUsing()`) and the
  URL signer's key (warehouse-level signed links). Account-level links are signed with APP_KEY (`Support\InstanceSignedUrl`: email
  verification, generated and checked). App code must not use `Crypt`/`encrypt()` for warehouse data (use
  `Model::currentEncrypter()`); the guard test enforces it. A first attempt that switched `app.key` itself had to pin cookies and
  CSRF back to APP_KEY and still broke the framework's own cookie handling in tests, so it was dropped.
- `instance:migrate` runs `WarehouseLayout` first: writes the key (the default warehouse's is the current APP_KEY) and moves
  `storage/app/private` into `<storage>/warehouses/<id>/files/`. Docker then runs `instance:rotate-app-key` once (new APP_KEY in the
  storage volume when the default warehouse's key still equals it; with APP_KEY set in compose it only says to change it).
- The `public` disk, the `storage:link`, and the `local` disk's `serve` routes (`/storage/{path}`, unused, and it took uploads) are
  gone. Backups write `files/private/` from the warehouse's folder; older backups' `files/public/` is accepted and ignored. The
  simulator's status file is in the warehouse's folder.
- Tests: the test warehouse's key differs from APP_KEY (like Docker after rotation), its files and key live under
  `storage/framework/testing/`. `tests/Unit/WarehouseIsolationGuardTest.php` fails on a literal connection name outside the
  control/accounts code, and on `storage_path('app…')` or a public disk outside `WarehouseLayout`.

**Still open from phase 1:** (the log context and URL root switch tasks were done in phase 2); the training class archive's
location across a training-warehouse reset (phase 3); a restore *replacing* the files folder rather than merging into it, and
per-warehouse backup directories (phase 4); an "import a warehouse key" step for restoring onto a new instance (today: set APP_KEY
before the first start, which seeds the first warehouse's key).

### Verified in practice (2026-10-27)

Beyond the test suite, on Daniel's machine:
- **Bare-metal upgrade:** the dev database built with the pre-branch code (an administrator, a staff login, an unverified registrant, a
  30-day simulation), then `instance:migrate`: the right people got accounts (3 logins + 40 partner organizations as invitations; no
  donors or trainees; only the Administrator an instance admin), 6,969 uploads moved and their stored paths resolve, and over HTTP old
  passwords work, failures are refused, and a password change ends the account's other sessions but not its own.
- **Bare-metal fresh install:** `instance:migrate` from nothing, then the setup wizard over HTTP.
- **Docker upgrade** of an existing stack (twice, the second time from copies of the volumes, which also proved rolling back works):
  pre-migration backup written, schemas created, key file written, uploads moved, APP_KEY rotated exactly once, and mail secrets the
  old code encrypted still decrypt under the warehouse key. Login, pages, queue and scheduler work; the next backup has accounts.json.
- **Docker fresh install** (wizard, login, a restart keeps sessions) and **disaster recovery** (the upgraded stack's backup restored
  with `backup:restore` onto the fresh instance; the old login works there).

Found by those runs and fixed: the Docker pre-migration backup tried to export accounts.json before the accounts schema existed (the
upgrade looped refusing to migrate); a restore collided on the old instance's `people.account_id` values; moving uploads took the
checkout's tracked `.gitignore` with it.

**Not yet run anywhere:** the R Station local server (compose and reset-script changes made, not run on a device); the RI Launcher
(its compose test passes, but no install was run); a federation identity surviving the Docker upgrade (the test stack had none);
the bare-metal demo/wa26 boxes, which need their `<db>_control` and `<db>_accounts` schemas created by hand **before** they are
deployed with this branch, or `instance:migrate` fails.

**Phase 2 — mostly done** (2026-10-28). Decided while building:
- **One named connection per warehouse** (`warehouse_<id>`, `SwitchDatabase`) instead of re-pointing one connection: switching away
  and back never drops a connection or the transaction on it. That is what makes a second warehouse testable: `tests/SecondWarehouse.php`
  (`SecondWarehouse::make($this)`) gives a real one — schema `<test db>_w2`, migrated once per process from the schema snapshot, its
  control row and its connection both inside transactions rolled back after the test. This settles the §13 test-setup problem.
- **No request-rejecting trusted-hosts check.** It would break the LAN, R Station and Launcher installs reached by IP address or `.local`.
  Instead, links that leave the browser never use the Host header: `Warehouse::publicUrl()` (primary hostname with APP_URL's scheme,
  else APP_URL, plus `?w=` when the address alone doesn't pick the warehouse) for emails, the reset link and the verification link (signed
  with APP_KEY, then `w` added; the `?w=` redirect keeps the rest of the raw query string byte for byte, so the signature still matches).
  In-page links people share (the `/join` QR code and poster, design-vote links) keep the request's address and get `withSelector()`.
  Outside a request (jobs, commands) `SwitchUrlRoot` roots URLs at `publicRoot()`. Consequence: on an install whose APP_URL is wrong
  (`localhost`), emailed links are wrong too, as they would be from a queued job today.
- **`RIK-` audience = host + the `/w/<id>` prefix** (when the client's server URL has one). Unprefixed credentials are unchanged, so no
  existing client is affected; only the Pi mints these (`server_audience()` in `rstation/product/backend/federation.py`), the Rust
  clients sign per request. One vector added to `federation-signing-vectors.json`.
- **Cookie and localStorage names:** the default warehouse keeps the old names (approved PIN devices stay approved); other warehouses get
  `_<id>` (`WarehouseContext::cookieName()`, the `storageSuffix` prop, `warehouseStorageKey()` in `bootstrap.js`).
- **Unresolved browsers land in the default warehouse** until the chooser exists (phase 3). Unprefixed machine URLs stay there for good.
- **A refused resolution doesn't `forget()`**: it returns before anything runs, and forgetting would push a test's later queries out of
  its transaction. A prefixed request's forced URL root is cleared at the start of the next request and on terminate.
- **`--warehouse=default`** is accepted (Docker entrypoint and backup script), and is a no-op before the control schema exists.

Built:
- `ResolveWarehouse`: `/w/<id>` prefix (machine surfaces `/api/*`, `/up`, the well-known document: stripped for routing, original URI in
  `ri.original_uri`, URL root `…/w/<id>`; any other GET: a landing link like `?w=`), mapped hostname (locked; `?w=` is dropped, a prefix
  for another warehouse is a 404), `?w=` (cookie `ri_warehouse`, not encrypted, then a redirect), the cookie, the default. Unknown or
  inactive ids are treated like no choice. Non-active warehouses get a 503 (phase 4 refines the statuses).
- `GuardWarehouseTab` (web group): `X-RI-Warehouse` (set on axios from the `warehouse` prop, which Inertia uses too) or `_w`; a mismatch
  is a 409 (`warehouse_switched`; Inertia gets `X-Inertia-Location` to `/warehouse-switched`); with several warehouses a non-GET or an
  Inertia visit naming none is refused; the session records its warehouse and is invalidated in another. `PrintMenu` adds `_w` to its
  download links (`withWarehouse()`), and so does every other plain link to a CSV, report, backup, photo or file (`$withWarehouse()` in
  templates, a global property set in `app.js`); `DownloadLinksGuardTest` fails on a new one that doesn't.
- Switch tasks `SwitchCache` (per-warehouse prefix on the default store; forgets cached stores and the rate limiter; the `accounts`
  store untouched), `SwitchLogContext` (`warehouse` on every log line), `SwitchUrlRoot`.
- Federation: `TrustAuth` checks signatures against `ri.original_uri` and credentials against host + prefix; `PeerUrl` keeps a `/w/<id>`
  path (and `FederationClient` sends and signs it), as does the Launcher's `normalize_server_url` (the print client already kept the
  typed path); the well-known document has `warehouse` and `shared_host`, and `DomainVerifier` won't verify a peer whose address is
  shared; a new identity's `base_url` is `Warehouse::machineUrl()`. `FEDERATION_AUTH.md` documents the prefix.
- Cookies: `pin_device_token`, `device_session`, `design_vote_token` per warehouse; `ri_print_last_<media>` per warehouse.
- Queue in the control schema (`jobs`, `job_batches`, `failed_jobs`; jobs left in a warehouse's old `jobs` table aren't run);
  `WarehouseJobs`: `warehouse` in every payload, switch on `JobProcessing` and back afterwards (a stack, so the `sync` queue inside a
  request works), a job naming no active warehouse fails. `FormSubmissionNotification` (the only queued job) is `ShouldBeEncrypted`.
- Commands: a global `--warehouse` (`WarehouseConsole`); with several warehouses any command not on its instance-level list refuses to
  start without one. `warehouse:list`, `warehouse:each "<command>"`; `docker/scheduler.sh` runs the volunteer sync and backup prune
  through it.
- Reverb: channels `w.<id>.print-queue` etc. (`Warehouse::channel()`), each authorisation also checks the id is the current warehouse.
- `feedback_reports.warehouse_id`, sent with pushed reports; central's `instance_identifier` includes a `/w/<id>` prefix.
- Tests: `WarehouseResolutionTest`, `FederationWarehousesTest`, `WarehouseBackgroundTest`.

**Still open from phase 2:** (download links: done, see above) the Docker pre-migration
backup and downgrade check cover only the default warehouse (phase 4 does every warehouse); the daily Docker backup is the default
warehouse's only (phase 4: per-warehouse folders); a `/w/<id>` landing doesn't check the warehouse's status beyond "active"; nothing
was run outside the test suite yet (two warehouses on one Docker stack, a Pi and a print client on a prefixed URL).

**Phase 2 verification plan (not yet run; 2026-10-28).** Locally, on Daniel's machine: (1) two warehouses on one Docker stack
(`docker/compose.dev.yaml`; a second warehouse can only be made by hand until phase 3: insert a `warehouses` row, schema, key file),
checking `?w=`, the cookie, two tabs on two warehouses (the 409 page), a download link from a stale tab, an emailed link's address,
`warehouse:each`, the queue picking the right warehouse, Reverb channel names; (2) the Rust print client against
`http://host/w/<id>` (`print-client/e2e/run.sh` with a prefixed URL), pairing, a job, and the same client paired with both warehouses
to prove a credential for one is refused by the other; (3) the Launcher's remote backup against a prefixed URL (`launcher/e2e/run.sh`).
Needs Daniel or hardware: the R Station Pi (a `RIK-` credential with a prefixed `server_url`, wake channel), a real label printer, a
phone on the LAN (`.local`, camera scan under `/w/…` is not affected, pages are not prefixed) and the R Station local server.

**Phase 3 — in progress** (2026-10-28). Slice (a), database users and the provisioner, is built; the rest is below. Decided:
- **Every warehouse, the default one too, has the standard schema name `<prefix>_<id>`** (prefix = the instance's own database
  name, e.g. `reliefinventory_k7q2x9md`) **and its own user `ri_<id>`.** The default warehouse's schema is *renamed* once, on the first
  start after upgrading (`RENAME TABLE` of every table in one statement, which keeps rows and foreign keys; tested). The old
  `reliefinventory` schema is left behind, empty, because the base connection and the entrypoint's database probe still name it.
  Bare metal and tests don't run a provisioner (`ProvisioningClient::applies()` is Docker only), so there the default warehouse keeps
  its schema name and the instance's own user.
- **The provisioner is a separate container** (`provisioner`, `ri-entrypoint provisioner`, `php artisan provisioner:work`) in
  `docker/compose.yaml`, the Launcher's embedded copy of it and the R Station's `offline/compose.yaml` (the Pi too: one more small PHP
  process). It is the only process with the database administrator password, which now lives in its own `admin-secrets` volume
  (mounted by `init`, `db` and `provisioner` only; `init` moves an old install's copy there). Web, queue and scheduler cannot read it.
- **Commands are plain rows** in the control schema (`provisioning_commands`: warehouse id, `create` | `adopt` | `drop`, status). The
  provisioner un-serializes nothing and **re-checks each command against the `warehouses` row** (`Provisioner::run()`): `create` only
  for a warehouse still `provisioning`; `adopt` only for the default warehouse, and only renaming the instance's own schema; `drop` only
  for an `archived` (or unfinished `provisioning`) warehouse that isn't the default; and every schema name must be the standard one, so a
  forged row can't make it drop `…_control`. Steps are idempotent and look at the state they find (a crash after the rename but before the
  row was updated, or after the user but before the password file, is repaired by running again; an interrupted `running` command is run
  again at start-up). The web side is `ProvisioningClient` (writes a row, waits up to `RI_PROVISIONER_WAIT_SECONDS`, 120);
  `instance:migrate` asks for every warehouse that still needs it before migrating it.
- **The provisioner also**: makes the control and accounts schemas and grants the base user on them (replaces `instance:ensure-schemas`,
  deleted; `app` now waits for them) and prunes MariaDB's slow-log table hourly (replaces `slowlog:maintain`, deleted, and the
  scheduler's call), so no application container needs root any more.
- **Grants** (`Provisioner::GRANTS`): `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES,
  LOCK TABLES` on the warehouse's own schema, re-applied narrowly each time; `max_user_connections` 20 (`RI_WAREHOUSE_DB_MAX_CONNECTIONS`).
  Tested as that user: can't reach another warehouse's schema, create a database, view, trigger or user, grant, or read a file.
  Backup and restore ran fine as that user on a real Docker stack.
- **The slow log** (`mysql.slow_log`) is read as the instance's base user, not a warehouse user, and once there are several warehouses
  Setup > Testing Tools shows no database slow log (it covers every warehouse; it belongs in the instance console, phase 5).
- **Verified for real** (2026-10-28): an old-layout stack (root password in `secrets`) upgraded in place to the new compose file: 96 tables
  renamed, `ri_<id>` made, root password moved out of `secrets` and unreadable from app/queue/scheduler, the app runs as the warehouse
  user, backup and restore work, a second restart changes nothing. Not run: the Launcher or R Station with the new compose files (the
  Launcher's compose tests pass; the Pi is untested).
- Tests: `ProvisionerTest` (uses the `ri_test_provisioner` user of scripts/TESTING.md and skips without it).

Decided 2026-10-28: `control` and `accounts` keep one user with ALL on both schemas (a read-only control user isn't worth the
complexity; read access for others will come through federation), and there is no password-rotation command (nothing enforces
rotation; the provisioner re-syncs a user to its password file, and re-running `adopt`/`create` makes a new file if it was lost).
Slice (a) still open: `create` makes the schema and user but nothing yet migrates or seeds a new warehouse (the next slices do).
**Slice (b), restore hardening, is built:** the model is in §7 ("Restore security model"): the client runs `--sandbox
--local-infile=0` as the warehouse's own user, and the dump is not parsed.
**Slice (c), setup codes and the awaiting-setup screen, is built for the fresh-setup path (2026-10-29); the restore path waits** (below).
- **Making one:** `php artisan warehouse:create "<name>" [--email=]` (`WarehouseCreator`, the browser console is phase 5) runs the §7 steps:
  row `provisioning` → schema + user (the provisioner on Docker; elsewhere `Provisioner::fromConfig()`, which needs `RI_DB_ADMIN_PASSWORD`) → key and
  files folder → `migrate` → seed essentials → `setup_state.completed_at` (so `/install` never runs in it) → setup code → `awaiting_setup`, last.
  Every step repeats, so `--resume=<id>` finishes one stuck in `provisioning`; `--new-code=<id>` replaces a code. It prints the address
  (`Warehouse::setupUrl()`: `/activate` on its own hostname, else `/w/<id>/activate`) and the code, shown once. Not built: emailing them (§13 mailer).
- **Codes** (`SetupCodes`, control table `warehouse_setup_codes`): `ABCDE-FGHJK` (10 characters, 32-letter alphabet), only the SHA-256 stored,
  7 days (`RI_SETUP_CODE_DAYS`), tied to one warehouse, typed in any case or spacing. Issuing revokes the previous usable one. The instance
  admin's list of awaiting warehouses and expiry is `SetupCodes::pending()`; the page for it is phase 5.
- **Reaching it:** `ResolveWarehouse` now treats `awaiting_setup` as reachable (`Warehouse::findReachable`: `?w=`, `/w/<id>`, the cookie, a mapped
  host) but such a warehouse answers only `/activate` and `/activate/*`: other pages redirect there, JSON/API/unsafe requests get 503. Its id is
  random, so (like an active one) it is only found by someone who was told it; an id that doesn't exist or isn't active/awaiting still looks like nothing.
- **The screen** (`WarehouseSetupController`, `Setup/Activate.vue`): the code, then who will run it: a **new login**, or an **existing login proved with
  its password** (verified, unlocked, a password set; its credentials are never changed; a membership and a person are added). Either becomes the
  Administrator. **Never an instance admin.** Optional example data. A right code puts a claim in the session; every later step checks the code is
  still usable, so revoking takes effect at once. Wrong tries are counted **per warehouse** (10 per 15 min, config `warehouses.setup_*`), not per
  visitor, for the code and the existing-login password alike, plus route throttles.
- **Finishing** (`WarehouseSetup`): the code row and warehouse row are locked in one control transaction around the administrator's creation
  (two holders of one code can't both finish; a failure keeps nothing and the code still works), then the code is used up and the warehouse goes `active`.
- Tests: `WarehouseSetupTest` (13) and `WarehouseCreateTest` (real schema, needs the provisioning test user).
- **The restore path (built 2026-10-29).** At `/activate` the code holder can choose "Restore a backup into it" instead of starting empty. They say who
  they are first (a new login, or an existing one proved with its password; wrong tries count against the warehouse) because replacing the database
  wipes the session. `RestoreController` serves `/activate/restore/*` too, gated by the code claim instead of "never set up". Launching stages a
  **claim file** (`<backup dir>/warehouses/<id>/restore/claim.json`: code id, account id, name; no secrets) whose presence makes the restore
  *provisioned* (`RestoreService`): the backup is untrusted (`relinkAfterRestore(untrusted: true)`: its existing logins become invitations,
  new ones unverified, no instance admin, **no orphan-login sweep**: the sweep deleted the holder's own new login, found by the test); no
  instance-wide `artisan down`; a backup without a database is refused; a failed load returns *this* warehouse to empty + essentials + latch
  (`recoverProvisionedState`, never `resetInstance()`, which deletes membership-less logins, instance admins included) with the code still usable;
  and on success the holder is added as Administrator (`WarehouseSetup::withExistingAccount(..., restored: true)`: the person in the backup with
  their email *is* them, otherwise a new person; nothing is seeded over the backup's roles), the code is used up and the warehouse goes `active`.
- **Per-warehouse restore folders:** `RestoreService::dir()` is now `<backup dir>/warehouses/<id>/restore` (uploads, work, status, lock, claim), and
  `launch()` starts `restore:run … --warehouse=<id>`, so warehouses can restore at once and one's progress never shows in another's browser. The
  first-run wizard restore (warehouse 1, `/install/restore`) uses the same folder scheme. Backup zips and their status files stay in the shared
  backup dir until phase 4.
- Tests: `WarehouseRestoreSetupTest` (real schema: the restore, the failure recovery, the gates).
**Slice (d), the chooser and domain mapping, is built (2026-10-29).**
- **Chooser** (`WarehouseChooserController`, `Auth/Login.vue`): the login page always names the warehouse once there are several ("Signing in to X"),
  and on the shared address offers "Not the right warehouse?": the `listed`, active warehouses as `?w=` links, plus a typed code. A **mapped
  hostname never shows a chooser** (it is locked, §3). The typed code is the warehouse's id (any case or spacing), posted to `/choose-warehouse`
  (10/min per address) and followed through the same `?w=` landing that sets the selector cookie; a warehouse awaiting setup is reached the same way
  and lands on `/activate`. A code that matches nothing, or a warehouse that isn't open, gets the same plain "No warehouse matches that code."
  Unlisted warehouses are never in the list.
- **Names are unique** (the control table already had the unique index): `WarehouseCreator` checks first and says "There is already a warehouse called …".
- **Domain mapping** (`WarehouseDomains`, `php artisan warehouse:domain list | add <id> <host> [--primary] | remove | primary`; the instance console, phase 5,
  will do it in the browser): a bare lower-case hostname with a dot; no scheme/port/path, no IP address, and never the instance's own address (APP_URL),
  which is the shared one and carries the default warehouse's machine URLs. A hostname reaches one warehouse only. A warehouse's first hostname is its
  primary (`publicRoot()`, used in emails); removing the primary promotes another. DNS and TLS for the host are outside the app and the command says so.
- **Turnstile hostname step:** with Turnstile on, `add` warns that the host must be added to the Cloudflare widget's hostnames. Cloudflare's siteverify
  answer names the host a passed check came from, so `TurnstileService` records `warehouse_domains.turnstile_confirmed_at` the first time that equals a mapped
  host; until then `warehouse:domain list` shows "NOT confirmed". It is a warning, never a block.
- Tests: `WarehouseDomainsTest`, `WarehouseChooserTest`. Not built: `skip_chooser` has no use yet (a mapped host never shows one).

- **Backup and restore, the whole picture (confirmed 2026-10-29).** Two kinds, both required: (1) **per warehouse**, the normal flow
  (that warehouse's database and files; a warehouse admin's own restore is the same engine plus the lineage check, phase 4); (2) **whole
  instance** (instance admin only): control + accounts + every warehouse's database and files, restoring them all onto a new instance.
  Not built: the first-run wizard's restore still restores only warehouse 1, so it needs an instance-level path once the whole-instance
  backup exists. Both are phase 4 (§11).

**Slice (g-lite), invitations, is built (2026-10-29).** One mechanism for every place a warehouse would attach a login it doesn't own (§6):
- **Model:** `membership_invitations` in the accounts schema (not in a warehouse, so a restored dump can't plant or accept one): one row per
  (warehouse, person), the invited email, status `pending|accepted|declined|withdrawn`, `source` `admin|restore|import`, an optional emailed
  link (only the SHA-256 of a 40-character token is stored), 14-day expiry (`RI_INVITATION_DAYS`). `App\Services\MembershipInvitations`:
  `invite` (renews an earlier offer; refuses a login already in the warehouse), `listFor`, `accept`, `decline`, `withdraw`, `findByToken`.
- **Accepting needs the login's own password** (`Account::verified`), never a PIN or tap session, both ways in: the **emailed link**
  `/invitations/{token}` on the inviting warehouse's address (public; the invitee can't be signed in there yet; accepting signs them in) and
  **`/account/invitations`** for a login signed in anywhere it belongs, which lists every invitation to its email, including ones never emailed
  (profile menu shows a count). Wrong passwords are counted per invitation / per account (5 per 15 min). Declining and an expired or
  withdrawn offer change nothing.
- **What accept does:** in the invited warehouse it links the person (`people.account_id`, `email`), only if that person still has no
  login and isn't a trainee, then makes the membership; if the second step fails the first is undone. It never changes the account's password,
  verification or instance-admin flag. The account page says what accepting means and, for a restore's invitation, that no person sent it.
- **Callers:** (1) **Users page**: adding someone whose email already has a login (it used to fail with "already used to log in") now makes the
  person without a login, with the roles chosen, and invites the login by email; the roles apply when it accepts, a typed password is ignored,
  and the page lists invitations still waiting with Resend / Withdraw (`/json/users/invitations`). An email with **no** login anywhere keeps the
  old path: the account is made and a "set your password" email goes out. (2) **Untrusted restore**: `WarehouseAccounts::relinkAfterRestore($export,
  untrusted: true)` invites an existing login instead of attaching it (nobody is emailed because a backup said so; the Users page can send it),
  creates a missing login *unverified*, and never makes an instance admin. Nothing passes `untrusted: true` yet: the setup-code restore does.
  (3) The people importers carry no login emails today; when one does it must use `invite()`.
- Tests: `MembershipInvitationTest` (service, link, in-app, Users page, untrusted restore). Not built: the old `move_logins_to_accounts`
  migration still attaches memberships when an older backup is migrated after a restore (an untrusted restore of an old backup must not
  reach it that way: part of the restore slice).

Order of the rest, agreed 2026-10-28: (b) restore hardening ✔; (c) setup codes and the awaiting-setup screen ✔ (restore path pending); (d) chooser and domain
mapping ✔; (g-lite) invitations ✔; the restore path of (c) ✔; (e) training warehouse and removing Training Mode; (f) removal.

### Remaining work

**Phase 2 — the rest:** see "Still open from phase 2" above. The chooser moved to phase 3 with the `listed` flag it needs.

**Phase 3 — provisioning and new warehouses:**
- Per-warehouse database users (`ri_<id>`, the narrow grant list, `max_user_connections`); the provisioner credential only in a small
  provisioning worker reading plain commands from the control schema (re-checked against it), not in the web container — which then
  stops needing the database root password (`instance:ensure-schemas` moves there).
- Restore hardening (done, 2026-10-28): see §7; the dump is deliberately *not* scanned for keywords.
- Setup codes (hash only, 7 days, single use, rate-limited) and the setup/restore screen for an `awaiting_setup` warehouse;
  provisioning sets `setup_state.completed_at` so `/install` never runs inside a provisioned warehouse.
- The chooser (`listed`, unique display names, the warehouse name shown on the login page, a rate-limited typed code with one answer
  either way); domain mapping (including the Turnstile hostname step).
- The training warehouse (seeded, simple local reset, the training switch as enable/disable, members kept across a reset via
  `memberships`), and removing in-warehouse Training Mode (§12); where the class archive lives across a reset.
- Warehouse removal (archive first, waiting period, final backup, typed confirmation, the account cascade, instance audit log).
- Invitations instead of direct memberships wherever someone else's account would be attached (§6), including a restore's
  accounts.json and the people importers; keep-and-report for the Names & addresses importer.

**Phase 4 — per-warehouse operations:**
- Per-warehouse `status` enforced by middleware (replacing `php artisan down` for restores and resets).
- Per-warehouse backups (`<backup dir>/warehouses/<id>/`, status files beside them), one-backup-at-a-time instance-wide, live-state
  tables left out of dumps (`--ignore-table-data`) and emptied after a restore.
- Single-warehouse restore as untrusted input (its own DB user, `--sandbox`, invitations, lineage uuid check; cloning another
  warehouse's backup is instance-admin only), replacing the files folder rather than merging into it, purging that warehouse's jobs.
- Hold-all upgrades (per-warehouse `migration_state` and pre-upgrade backup ids, the unblock screen with its commit-point warning,
  a disk-space check, the web container's longer start-up), and the global backup.
- An "import a warehouse key" step for restoring onto a new instance (today: set APP_KEY before the first start).

**Phase 5 — instance console** (its design is still open, §13): reached with the step-up re-authentication (account password, never a
PIN); mail settings and Turnstile keys move there (mail to the control schema, encrypted with APP_KEY); software update, Docker/TLS,
Reverb, logs and the slow log, disk use per warehouse, creating/suspending/archiving/removing warehouses, setup codes, global backups;
support sessions (synthetic Administrator, time-boxed, audited, a notice to the warehouse's admins); account lock and instance-admin
management in the UI; splitting `SystemAdmin.vue`, `/setup/email` and `/setup/testing` into instance vs. warehouse parts, including on a
single-warehouse install.

**Smaller items carried from 0b/1:** self-service email change with password + an APP_KEY-signed confirmation link; `setup:reset` must not
remove an instance admin who still runs another warehouse; review and probably delete the bare-metal tiered-backup path.

