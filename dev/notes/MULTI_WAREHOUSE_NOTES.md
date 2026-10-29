# Multi-warehouse: implementation notes

Design and rationale: `/MULTI_WAREHOUSE.md` (§18 has the phase status and the command table). This file is the "how it's wired" cheat sheet.

## Rules that bite
- Every request/job/command runs in exactly one warehouse (`WarehouseContext`). `ResolveWarehouse` picks it from the hostname, `/w/<id>` on machine URLs, `?w=` or the `ri_warehouse` cookie. Every request names its warehouse (`X-RI-Warehouse`, `GuardWarehouseTab`). Commands take `--warehouse`.
- Emailed links come from `Warehouse::publicUrl()`, never the Host header. Long-lived cookies use `WarehouseContext::cookieName()`. Broadcast channels use `Warehouse::channel()`. Don't assume `storage_path()` is shared.
- Only the `provisioner` container holds the database administrator password (the `admin-secrets` volume). Never read it from app code; go through `ProvisioningClient`.
- Tests needing a second warehouse use `tests/SecondWarehouse.php`.
- An instance admin is assumed able to run any command on the box. Never enforce a rule *against* the instance admin in software; enforce isolation *between warehouses*.
- Don't add anything to a real warehouse's behaviour for training (see `TRAINING_WAREHOUSE.md`).

## Lifecycle
- Create: `warehouse:create` (`WarehouseCreator`) leaves it `awaiting_setup`; it answers only `/activate`, where the holder of the one-time setup code (`SetupCodes`: hash only, 7 days, wrong tries counted per warehouse) becomes its Administrator (never an instance admin) → `active` (`WarehouseSetup`). Or they restore a backup into it (`/activate/restore/*`, claim file in the warehouse's restore folder `<backup dir>/warehouses/<id>/restore`).
- Login page shows the warehouse name; on the shared address only, a chooser (`listed` warehouses + typed id, `/choose-warehouse`). `warehouse:domain` maps hostnames (`WarehouseDomains`); a mapped host is locked to its warehouse. Turnstile hosts are flagged until a check succeeds on them.
- Remove: `warehouse:archive` (undoable) then `warehouse:remove` (typed name, final backup in `<backup dir>/removed-warehouses/`, drop, files, logins belonging only to it, control rows; `WarehouseRemoval`). The Instance Admin UI re-asks the password on every write (`guarded()`) and waits `WarehouseManager::REMOVAL_WAIT_DAYS` (30) after archiving; the command has no wait.
- Instance-level actions go to `instance_audit_log` (`InstanceAuditLog::record`).
- Docker: each warehouse has its own DB user `ri_<id>` made by the `provisioner` container; the default warehouse's schema is renamed once to `reliefinventory_<id>`.

## Accounts and memberships
- Credentials live in the accounts schema: `accounts` (email, hash, verification, lock, `is_instance_admin`, `auth_epoch`) + `memberships` (account → warehouse → person). `people.account_id` is a cached link; `people.email` mirrors the login.
- `password`/`email_verified_at` on Person/User are virtual, backed by the account (`Concerns\HasAccount`, which overrides `save()`/`delete()` — not model events, so `Event::fake()` can't skip it — and refuses a signed-in user changing someone else's login unless this warehouse is its sole home).
- Login: `App\Auth\AccountUserProvider`. A User "has an account, or is a trainee". A password change/reset/lock bumps `auth_epoch`, ending other sessions (`AccountSession`, `EnsureAccountSessionCurrent`).
- Attaching a login a warehouse doesn't own is an **invitation** the owner accepts with their password (`MembershipInvitations`, `/invitations/{token}`, `/account/invitations`). Never write a `memberships` row for someone else's account directly.
- Backups carry `database/accounts.json` (`WarehouseAccounts`), relinked after every restore.

## Backups and restore (details: `scripts/BACKUPS.md`)
- Per warehouse: `<backup dir>/warehouses/<id>/`, one at a time instance-wide via `.instance-backup.lock`. `config/backup.php` `live_state_tables` keep their rows out of dumps and are emptied by a restore.
- Restores are untrusted input: run as the warehouse's own DB user with the `mariadb` client in `--sandbox --local-infile=0` (`DatabaseTools::clientCommand()`). The grants bound the SQL; the dump is deliberately not parsed for keywords — don't add a filter. Restored logins become invitations; a failed load returns the warehouse to empty.
- A forced restore takes only that warehouse out of service (status `restoring`, ended by `warehouse:resume`), never `artisan down`.
- A warehouse admin restores into their own warehouse at `/setup/restore` (`RestoreService::stageOwnRestore`): same-lineage backups only (uuid in the manifest; an instance admin may clone another's), files replaced, jobs purged, accounts relinked untrusted, the admin stays Administrator. Progress: `/w/<id>/restore-status`.
- Rollback snapshots: the scheduler asks every warehouse hourly; its `backup_schedule` row (hourly/daily/weekly/monthly tiers, `BackupSchedule::due()`) decides. One zip carries every tier it satisfies; `BackupService::prune()` keeps a zip while it is among the newest N of any tier. Rollback: `BackupController::restoreSnapshot` → `RestoreService::runSnapshot` (a `before-rollback` zip first).
- **Warehouse key**: a warehouse backup never carries it (it isn't `APP_KEY`). The "Warehouse key" panel on Updates & Backups reveals it; restoring onto a new instance takes it at the restore step, via `warehouse:key import`, or `restore:instance --keys-dir` / `--with-keys`.
- **Whole-instance backup** (`InstanceBackupService`, `instance:backup`, `restore:instance`; CLI in the `provisioner` container only): one zip of control + accounts + every warehouse schema, optionally files and keys (passphrase-encrypted). Also the pre-upgrade backup (`ri-db-backup premigrate`). Trusted input: no relinking; nothing here is for a warehouse admin. Keys are sealed to the **recovery key** (`App\Support\RecoveryKey`, `InstanceRecoveryKeys`, control table `instance_recovery_key`): the public half lives on the instance; the private half (`RK1-…`) is shown once to an instance admin and stored nowhere — never log it or write it to the database. `restore:instance --recovery-key-file` opens it first.
- Scheduled instance backups: `instance_backup_schedule` (control; off until enabled) is read by `instance:backup --scheduled`, which asks the provisioner (`backup-sched`; *Back up now* writes `backup-now[-files]`). Due-rules are `SnapshotRules`, shared with snapshots.
- **Instance trust** (`InstanceTrust`, permission `instance.backup`, `/api/instance/v1`, `InstanceTrustAuth`): how the RI Launcher takes instance backups. Signature check is `VerifiesSignedRequests`, shared with `TrustAuth`; a warehouse trust never passes at the other's endpoints.

## Docker self-update
`InstanceUpdate` (control table `instance_update`), `docker/updater/ri-updater.py`. The app can't run `docker compose`: Instance Admin (`/setup/instance`, `InstanceAdmin.vue`, `instance-admin` middleware; JSON under `/json/instance/*`) or `instance:update check|apply|status` only sets a flag. A host Python service polls `GET /api/instance/update`, does pull → (newer `RI_VERSION` only) `down` → `up -d`, and reports to `POST /api/instance/update/status`. `RI_UPDATE_SECRET` (compose.yaml) is the only credential (`InstanceUpdateSecret`; unset = 404) — never trust the caller's IP (Docker's bridge hides it). The bare-metal web updater (`SystemController::update()`/`checkUpdates()`, `scripts/update.sh` from the web page) is deprecated: don't extend it.
