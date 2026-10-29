# Test database isolation

`vendor/bin/pest` uses Laravel's `RefreshDatabase` trait (see `tests/Pest.php`), which truncates
and rebuilds whatever database the app is configured to use. **`phpunit.xml` must always point
this at a disposable database, never the app's real one** — see the incident/fix in project memory
(2026-08-17): a commented-out `DB_CONNECTION`/`DB_DATABASE` override in `phpunit.xml` let the test
suite fall through to `.env`'s real database, wiping real dev data twice in one session before it
was caught.

**Schema snapshot (2026-10-22).** Running every migration from scratch takes ~50s on MariaDB, and the
suite used to pay that once per parallel worker plus ~10 times in `RestoreTest` (~25 min total). The
first from-scratch migrate now dumps the finished test database — schema *and* data, since many
migrations insert lookup rows — to `storage/framework/testing/schema-snapshot-<hash>.sql`, and every
later `migrate:fresh` loads it instead (`tests/SchemaSnapshot.php`, `tests/RefreshDatabaseFromSnapshot.php`).
The hash covers every migration file's name and contents, so adding or editing a migration rebuilds it
on the next run; deleting the file is always safe. A full `pest --parallel` run is now ~3 min. In a test
that needs a clean schema mid-test, call `Artisan::call('migrate:fresh', SchemaSnapshot::freshOptions())`,
not a bare `migrate:fresh`.

**Control and accounts schemas (2026-10-27, multi-warehouse).** Besides the main test database, the suite uses
`<test db>_control` and `<test db>_accounts` (`tests/InstanceSchemas.php`), named after the test database in use, so
each `pest --parallel` worker gets its own. They are created if missing and migrated from scratch once per process,
and every test runs inside a transaction on all three. The test user needs to be able to create them, and the
provisioning tests need a separate user that can create database users. One-time setup, as MariaDB root:

```sql
GRANT ALL PRIVILEGES ON `test\_reliefinventory%`.* TO 'reliefinventory'@'localhost';
CREATE USER IF NOT EXISTS 'ri_test_provisioner'@'localhost' IDENTIFIED BY 'ri_test_provisioner';
GRANT CREATE USER ON *.* TO 'ri_test_provisioner'@'localhost';
GRANT ALL PRIVILEGES ON `test\_reliefinventory%`.* TO 'ri_test_provisioner'@'localhost' WITH GRANT OPTION;
```

The pattern grant covers every schema whose name starts with `test_reliefinventory`. When granting on one
exact schema name, escape its underscores (`` `test\_reliefinventory\_ab12` ``): MariaDB refuses a `GRANT` on a
name that only a wildcard pattern matches. `InstanceSchemas` refuses any schema name without "test" in it.

## Why a real MariaDB test database, not SQLite

`phpunit.xml` points tests at a **real MariaDB database**, not SQLite in-memory. This app has
MySQL-specific migration/query behavior (see the `dropForeign('name_string')` fix in
`2025_01_24_013228_update_items_table.php` — valid Laravel/MySQL code that only fails under
SQLite's grammar). Testing against a different engine than production risks tests passing on
behavior that's actually broken on the real database. Keep the test database on the same engine as
production.

## Current setup

Every environment needs its own disposable database, separate from that environment's real
app database, with the app's DB user granted access to it:

| Environment | App database(s) | Test database | Notes |
|---|---|---|---|
| Local dev (this Mac) | `reliefinventory` | `test_reliefinventory` | Covered by MariaDB's default `test\_%` PUBLIC grant — no extra privileges needed. |
| demo / wa26 (shared Linode, shared MariaDB server) | `reliefinventory_demo`, `reliefinventory_wa26` | `reliefinventory_test` (shared by both — fine, `RefreshDatabase` runs are short-lived and nobody runs tests on these boxes routinely) | No `test\_%` grant exists on this server; created `reliefinventory_test` + `GRANT ALL PRIVILEGES ON reliefinventory_test.* TO 'reliefinventory'@'localhost'` manually via `sudo mysql` (root socket auth) on 2026-08-17. |

`phpunit.xml`'s `DB_DATABASE` value differs between the local repo and the deployed boxes for this
reason (`test_reliefinventory` vs `reliefinventory_test`) — this is a manual one-off fix applied
directly on the demo/wa26 filesystem on 2026-08-17, ahead of (and independent of) any actual code
deploy, specifically so the exposure was closed immediately rather than waiting for an unrelated
feature branch to ship. When this fix's actual commit is eventually deployed via
`scripts/update.sh`, it will `git reset --hard` `phpunit.xml` back to the repo's own
`test_reliefinventory` value — **at that point, either update the repo's `phpunit.xml` to use
`reliefinventory_test` for consistency, or re-apply the box-specific database name after deploy.**

Also note: as of 2026-08-17, demo/wa26 don't have Pest/PHPUnit installed at all
(`composer install --no-dev` in `scripts/update.sh` skips dev dependencies) — so this fix is
currently a safeguard against someone manually installing dev deps to debug something on a live
box, not a day-to-day live risk there. It remains a real risk on any machine (including a future
dev's laptop) where dev dependencies **are** installed and `.env` points at real data.

## Future work: automate this in a provisioning/install script (not yet built)

Right now, standing up a new instance (a third Linode box, someone's local machine, etc.) requires
remembering this step by hand — nothing currently creates a paired test database + grant
automatically. A future install/provisioning script (whatever eventually formalizes fresh-instance
setup, alongside the app database creation itself) should:

1. Create the app database (e.g. `reliefinventory_<name>`) and its test-database counterpart
   (e.g. `reliefinventory_<name>_test` or a shared `reliefinventory_test` if the box hosts multiple
   instances) in the same step.
2. Grant the app's DB user privileges on both.
3. Write (or verify) `phpunit.xml`'s `DB_DATABASE` to match the test database name for that
   instance, so a fresh instance is protected from day one instead of needing this same manual fix
   repeated.

This is explicitly scoped for later — not blocking anything today.
