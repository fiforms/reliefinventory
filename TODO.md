Issues identified August 16, 2026

1. ~~Implement user management and permissions setup for administrators under the Setup Menu -> User Administration~~ — done 2026-08-18
 - Built `/setup/users` (permission: `manage-users`, Administrator-only by default): create a
   login-capable account (sends Laravel's stock password-reset email so the person sets their
   own password — no separate invite-token system), promote/change roles and per-person
   permission overrides, deactivate/reactivate (new `disabled_at` column on `people`, checked
   in both real login and PIN-unlock login paths), resend the setup email. A
   Warehouse Users/Customers toggle filters the same list by role bucket.
 - Two new roles added: "Sorting and Inventory" (warehouse-only bundle) and "Office"
   (everything `Volunteer`/`Team Leader` already granted, i.e. "everything except
   admin/setup", just under an honestly-named role — renamed from "...Staff" 2026-08-18,
   since "staff" implies paid employment and this app doesn't track that). `Customer` role is
   reused as the login-capable "Customer/Client (Ordering Only)" role rather than adding a
   separate one.
 - `/setup/people` no longer shows permission-override checkboxes at all, and its Roles picker
   is now filtered to Customer/Donor only (`/json/roles?context=people`) — staff roles are
   assigned exclusively from the new page. `Role` gained two visibility flags
   (`visible_in_people_form`/`visible_in_user_admin`) driving both pickers.
 - On `/setup/users`, roles are one-tap, mutually-exclusive presets over a flat permission
   checklist (Grant All/Revoke All also available) rather than a role multi-select + separate
   3-way override table — tapping a role fully replaces the checklist with exactly that role's
   bundle (switching Admin -> Customer clears everything, doesn't union), then individual
   permissions can be hand-adjusted. `RoleController::index` now always eager-loads each role's
   permission keys so the page can preview/apply them. Backend role-default + per-person-override
   semantics are unchanged — this is a UI reinterpretation only.
 - "Volunteer" is no longer a role at all — it's `people.is_volunteer` (a fact about the person,
   independent of their permission role or party role; an administrator or office role-holder
   can still be a volunteer). Editable from both `/setup/people` and `/setup/users`. Feeds the
   not-yet-built volunteer-hours/FEMA-reporting tracking in `PROJECT_ANALYSIS.md` Part 5. The
   `Volunteer` role row still exists in the database (not deleted, for historical/FK safety) but
   is hidden from both pickers (`visible_in_people_form`/`visible_in_user_admin` both false);
   anyone who held it got `is_volunteer` backfilled to true by the migration.
 - **Known gap, deliberate**: the Customer/Client role currently carries no `manage-orders`
   grant — that permission is resource-level (all orders), not row-level, so granting it today
   would let one customer see/edit every other customer's orders. A Customer-role account can
   log in but can't do anything yet, until order-ownership scoping is built (see the note
   below).
 - **Deferred, not built**: a much bigger idea surfaced while scoping this — full self-service
   customer registration with an approval funnel (pending → approved, blocking order access
   until approved), plus "customers see/edit only their own orders." `PROJECT_ANALYSIS.md`
   Part 5 (Facility Network) already designs a general approval-workflow mechanism
   (`facility_assignments`, `approval_status`/`active_status`) that's the right home for this
   rather than a one-off field bolted onto `Person`. Needs its own design/build pass — row-level
   order ownership in `OrderController` especially, since nothing in the permission model
   today expresses "this resource, but only rows you own."
 - Found and fixed a real latent bug while touching `AuthenticatedSessionController`: the
   `MustVerifyEmail` unverified-email check referenced the class with no `use` import — any
   login attempt that actually hit that branch would have fatal-errored instead of showing the
   verification-required message. Never previously exercised by a test.

2. ~~Troubleshoot page breaks on PDF reports~~ — done 2026-08-17
 - Inventory Report PDF (/report/inventory.pdf) breaks pages in the middle of a table.
 - Same issue on Order Entry Offline Order Form (/report/order-form.pdf)
 - Fixed, then reworked further: both reports now render via a WeasyPrint driver (real CSS
   Paged Media — `@page`, `@page :first`, margin-box running header/page-numbers) instead of
   headless Chrome, giving correct per-page margins, a running header absent on page 1, and
   category headings that repeat on continuation pages. See CLAUDE.md's PDF/label generation
   section for the technical detail.

3. Important PDF Reports such as the inventory should also be available as a CVS (or XLSX) Spreadsheet Download

4. Order Entry
 - ~~There's a bug in the search box: when I type an item number or name, it shows only numbers in the search results.~~ — done 2026-08-18
   - Added a reusable `secondary` prop to `SearchSelect.vue` (any page searching item types, not
     just this one, can now show a primary + secondary field with a wider dropdown) and used it
     for the item # search here. Also fixed the item # field not clearing after adding a line
     (a focus-timing race), the dropdown popping open with the top row pre-selected on refocus,
     and reworked the duplicate-item flow into a proper modal with a Tab focus trap — see git
     history 2026-08-18 for the full set of order-entry UX fixes from this session.

 - ~~There is no place to enter a UNIT in conjunction with a quantity~~ — not a bug: an item type
   is only ever orderable in the single unit set for it elsewhere (Master Item List), so there's
   nothing to pick per line on the order sheet itself. That unit still shows on every order
   line (`OrderController` loads `itemtype.unit`). Item 5 below (expanding/managing that unit
   list) is the real remaining work in this area, not a per-line picker here.

 5. Master Item List
  - Unit (Measured By) should be labeled "Default Ordering Unit."  This list should be configurable per instance (separate table) and should be expanded to include at least the following:
    - Case
    - Bag
    - Pallet
    - Jug
  - Create a user interface to add and manage default ordering units


 6.  Under "Receiving" and "Donation Sorting" there should be an option (perhaps a lightbox) that would allow quickly adding a donor without navigating away from the page. 
  - Also in the dropdown, a number of entries are showing blank lines. It's showing the organization field, but not the name, so individual's names are appearing blank

Issues identified August 17, 2026

7. Provisioning/install script for new instances (scoped for later — see scripts/TESTING.md)
 - Standing up a new instance currently requires manually creating both the app database and a
   separate disposable test database (with the app's DB user granted on both), then pointing
   phpunit.xml at the test one — done by hand for demo/wa26 on 2026-08-17 after discovering the
   test suite had been wiping real data (it fell through to the real DB when no override existed).
 - A future install/provisioning script should do this automatically for any new instance:
   create app DB + paired test DB, grant the app user on both, and set phpunit.xml's DB_DATABASE
   to match — so a fresh instance is protected from day one instead of needing this fix repeated.
 - Also needs to create that instance's systemd units (`<name>-update.service`, `<name>-backup.service`,
   `<name>-backup.timer`, `<name>-queue.service`), following the pattern the wa26 units already show —
   `Environment=APP_DIR=/var/www/reliefinventory-<name>`, `BACKUP_DIR=/var/backups/reliefinventory-<name>`,
   `QUEUE_SERVICE=reliefinventory-<name>-queue` — each pointing at that instance's own directory. Without
   these, only a manual `cd <instance-dir> && sudo bash scripts/update.sh` works for that instance (fixed
   2026-08-18 to correctly default to whichever instance dir it's run from — see git history on
   scripts/update.sh); there's no admin-panel "update now" button or scheduled backup until the units
   exist. The app directory must be named `reliefinventory-<name>` for `update.sh`'s defaults
   (`QUEUE_SERVICE`, `BACKUP_DIR`) to resolve correctly without explicit overrides.

8. ~~Set FEEDBACK_NOTIFY_EMAIL in .env on demo and wa26~~ — done 2026-08-17
   (daniel@pastordaniel.net, hellopastormark@gmail.com on both instances).

9. Deploy step: install the `weasyprint` binary on any server this app runs on
 - The Inventory Report and Order Form PDFs (see item 2 above) now render via a `weasyprint`
   driver instead of headless Chrome. This needs the `weasyprint` Python package (with its
   native Pango/Cairo deps) provisioned on the box — `apt install weasyprint` or
   `pip install weasyprint` depending on distro — same one-time-setup category as the earlier
   headless-Chrome library requirement. Not part of `scripts/update.sh`; needs doing manually
   once per server. Pallet labels and the SITREP are unaffected — they stay on the existing
   Chrome-based driver.

10. ~~System Admin "Install Update" silently triggered the wrong instance's systemd unit on wa26~~
    — fixed 2026-08-18
 - Incident: clicking "Install Update" on wa26's `/setup/system` panel wrote wa26's own
   "Update requested…" status file, but then told `sudo systemctl start` to fire
   `reliefinventory-update.service` (demo's unit) instead of
   `reliefinventory-wa26-update.service` — because wa26's `.env` never set
   `SYSTEM_UPDATE_UNIT`/`SYSTEM_BACKUP_NOW_UNIT`, so `config('system.update_unit')` silently
   fell back to the literal default value, which happens to be demo's unit name. Demo only
   "worked" by coincidence (its unit name matches the default). `journalctl -u
   reliefinventory-wa26-update.service` showed zero activity ever; `reliefinventory-update.service`
   (demo's) fired at the same instant instead, ran a harmless no-op against demo, and never
   touched wa26's status file — leaving the wa26 panel stuck on "waiting for the updater to
   start" forever, and (worse) permanently 409-refusing any retry since the backend blocks a
   new update whenever `state === 'running'`, with no timeout anywhere.
 - Fixed both instance-specific gap and the underlying design gap:
   - Added the missing `SYSTEM_UPDATE_UNIT=reliefinventory-wa26-update.service` /
     `SYSTEM_BACKUP_NOW_UNIT=reliefinventory-wa26-backup-now.service` to wa26's `.env` (and made
     demo's explicit too, instead of relying on the default matching by coincidence).
   - `SystemController::readUpdateStatus()` now treats a `running` status whose `updated_at` is
     more than 5 minutes stale as `stalled` (computed on read, nothing written back — a real
     update that does start simply supersedes it on the next write). `update()`'s "already
     running" 409 guard naturally stops blocking once a status is stale. The panel
     (`SystemAdmin.vue`) now shows a clear "Update stalled: …" error instead of polling a dead
     status forever.
 - **Any future new instance's `.env` must set these two vars explicitly** — there's no
   provisioning script yet (see item 7) to catch this automatically.