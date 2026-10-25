# 0001 — Training alongside live work

- **Status:** Under discussion
- **Lane:** Warehouse flow (Mark) for the requirements; **the storage approach is Daniel's call**
- **Author:** Mark
- **Idea:** relates to ROADMAP.md §2 (multi-tenant) and §4 (federated instances)

## Problem

Training Mode today switches the **whole instance**. Turning it on takes a reset-point backup, and "turn off and reset"
restores it. That is fine on a dedicated training instance (cs26), but it can't be used on a warehouse that is
working for real:

- While it's on, practice records land in the same tables as real ones, so reports pulled during a class are skewed.
- The reset restores the whole database, so it would also erase every **real** record entered during the class.

In a real warehouse you want to train people beside the live operation. They should be able to make mistakes on
realistic data before they're turned loose on live data, while everyone else keeps working normally.

## Requirements (operational)

1. Trainees work on a realistic copy of the warehouse's data: the same items, pallets, locations, partners and
   orders as live at the moment training started.
2. Trainees can change anything in their copy (create, edit, delete) with no blocks. To them it behaves exactly like
   the live system.
3. **Nothing a trainee does ever reaches live data, live reports or live users.** People not in training see only
   live data, as they do today.
4. Ending training never touches live data. Practice data is thrown away (after the class archive of report cards
   and certificates is saved, as today).
5. **Every training output carries an obvious TRAINING watermark**, including downloaded PDFs, in case one is printed
   outside the system. A training pallet or item label must never be mistakable for a real one on the floor.
6. Training never sends real email or notifications to partners, donors or staff.
7. A dedicated training instance stays a supported option. Some classes will still run that way.

## Decided

- **No continuous live → training sync.** Trainees don't need the truck that arrived ten minutes ago, and a sync
  brings in conflicts: live changes a record a trainee already changed, and someone has to win. A copy taken when
  training starts, with an optional manual "refresh from live" that discards practice work, is enough.
- **No per-table shadow copies inside the live database.** Every read would have to merge two copies (stock-on-hand,
  reports, PalletStock and PickPlanner all aggregate `item_ledgers` with joins). The app has no soft deletes (about 46
  hard deletes), ids would collide, and every migration would need a shadow twin. One missed query leaks training data
  into live.

## Open questions — Daniel's lane

1. **How to store the training copy.** Two candidates:
   - **A separate training database on the same instance**, cloned from live with `DatabaseTools` when training starts
     and dropped at the end. A training login switches that session's connection, and no queries change. This is the
     database-per-tenant model, with training as one tenant of kind `training`.
   - **ROADMAP §2's shared database scoped by `facility_id`**, with training as a scope. This is cheaper on
     infrastructure, but every query has to respect the scope, which is the leak risk §2 already names.
2. **The tenant vs. facility boundary.** Is a tenant an organization that contains facilities sharing stock and
   transferring between them (PROJECT_ANALYSIS.md Part 5), or is each warehouse its own tenant? This decides where
   person-to-tenant access and per-tenant roles live. Mark is still thinking about this one too.
3. **Where people live.** `people` holds both login accounts and donors, partners and drivers. With separate
   databases, logins (and which tenants each person may enter) would need a central home.
4. What has to carry the training/tenant context outside a web request: queue jobs, cache and session keys, uploaded
   files, broadcasts, print servers and devices, backups, migrations.

## Open questions — shared

- **Printing from training.** Training jobs go to real printers with the TRAINING watermark, or training is PDF-only
  (still watermarked). Training labels should also use numbers outside live's range.
- Who may start or stop a training copy, and whether more than one can exist at a time.

## What it touches

Training Mode (`TrainingMode`, `TrainingReset`, the training login, report cards), `DatabaseTools` / `BackupService`,
every PDF builder (the watermark), the print queue, mail (`MailSettings`), and ROADMAP §2/§4 if it goes the tenant
route.
