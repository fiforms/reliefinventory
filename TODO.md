Issues identified August 16, 2026

1. Implement user management and persmissions setup for administrators under the Setup Menu -> User Administration. Have the ability to
 - Create new users who will receive an email to set up their password
 - Promote or change permissions on users who've registered online
 - Deactivate users
 - "People" form in main application shouldn't show permissions, there needs to be a separate UI in the administrative side. "Roles" visible in the main application should be only "Customer/Donor/Volunteer"
 - Administrative page should have roles like "Administrator" (everything), Sorting and Inventory Staff, Customer/Client (Ordering Only), Office Staff (everything except admin/setup roles)

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
 - There's a bug in the search box: when I type an item number or name, it shows only numbers in the search results.
   - It should match the dropdown already implemented in receiving
   It should show a wider dropdown with both the number and name

 - There is no place to enter a UNIT in conjunction with a 

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