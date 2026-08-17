Issues identified August 16, 2026

1. Implement user management and persmissions setup for administrators under the Setup Menu -> User Administration. Have the ability to
 - Create new users who will receive an email to set up their password
 - Promote or change permissions on users who've registered online
 - Deactivate users
 - "People" form in main application shouldn't show permissions, there needs to be a separate UI in the administrative side. "Roles" visible in the main application should be only "Customer/Donor/Volunteer"
 - Administrative page should have roles like "Administrator" (everything), Sorting and Inventory Staff, Customer/Client (Ordering Only), Office Staff (everything except admin/setup roles)

2. Troubleshoot page breaks on PDF reports
 - Inventory Report PDF (/report/inventory.pdf) breaks pages in the middle of a table.
 - Same issue on Order Entry Offline Order Form (/report/order-form.pdf)

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

8. Set FEEDBACK_NOTIFY_EMAIL in .env on demo and wa26 (feedback reporting feature, deployed
   2026-08-17, commit 27491de)
 - Reports submitted via "Report an Issue" save fine either way, but the developer notification
   email (FeedbackReportController@notifyDevelopers) is silently skipped whenever this is unset —
   nobody currently gets emailed when a report comes in on either live instance.
 - Comma-separated developer addresses, added directly to each instance's .env (untracked, not
   part of any deploy step — see .env.example for the format/comment). Do this by hand on
   /var/www/reliefinventory-demo/.env and /var/www/reliefinventory-wa26/.env, then
   `php artisan config:cache` (as www-data) on each so the new value actually takes effect.