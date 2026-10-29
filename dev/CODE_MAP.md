# Code map: where is the code for X?

A starting index, not a full inventory. Find an area, then open the files it names (each model and service has a header comment with
its rules). Names are relative to `app/` unless they start with `resources/`, `routes/`, `tests/` or another top directory. For
subsystem rules read the matching file in [notes/](notes/); the doc map is [README.md](README.md).

## How the layers fit

- **Page route** (`routes/web/<area>.php`, indexed by `routes/web.php`; rules in [notes/ROUTES.md](notes/ROUTES.md)) renders an Inertia page in
  `resources/js/Pages/<Name>.vue`; the page loads its data from `/json/*` routes in the same file. A page and its `/json` endpoints
  use the same permission key (`Models/Permission.php`, `database/seeders/PermissionsSeeder.php`, `Models/Concerns/HasPermissions`).
- **Menu tiles** are `MenuItem` rows (seeded), each with a `permission_key`.
- **Machine APIs** (devices, federation, print clients, the Launcher) are in `routes/api.php`; their auth is `TrustAuth` /
  `InstanceTrustAuth` middleware ([notes/FEDERATION.md](notes/FEDERATION.md)).
- **Controllers** (`Http/Controllers/`) are mostly one per resource, named for it. Business rules live in the models and `Services/`.
- **Tests** mirror controller or feature names in `tests/Feature/`; look for `<Thing>Test.php`.

## Stock, items and locations ([notes/STOCK_AND_LOCATIONS.md](notes/STOCK_AND_LOCATIONS.md))

| Concern | Where |
|---|---|
| Ledger (source of truth for stock) | `Models/ItemLedger.php`, `Services/PalletStock.php`, `Http/Controllers/PalletStockController.php` |
| Item catalogue, types, categories, units, package types | `Models/{Item,ItemType,Category,Unit,PackageType}.php`; controllers of the same names; pages `ItemEntry`, `CategoryEntry`, `Units`, `PackageTypes` |
| Locations and pick path | `Models/Location.php`, `LocationController`; pages `LocationEntry`, `PickPath` |
| Pallets (status, location, stock) | `Models/{Pallet,PalletStatus}.php`, `PalletController`, `PalletStatusController`; pages `PalletManagement`, `PalletLocation` ("Inventory Movement") |
| Inventory labels and codes | `Support/{InventoryLabelCode,LabelBarcode,PalletKind}.php`, `InventoryLabelController` |

## Receiving and sorting ([notes/RECEIVING.md](notes/RECEIVING.md), [notes/SCANNING_INTERFACE_NOTES.md](notes/SCANNING_INTERFACE_NOTES.md))

| Concern | Where |
|---|---|
| Receiving wizard, donations as transactions | `ReceivingController`, `Models/Transaction.php`, page `Receiving` (a legacy `DonationController` is slated for removal, see [TODO.md](TODO.md)) |
| Donation offers | `DonationOfferController`, `Models/DonationOffer*.php`, page `DonationOffers` |
| Trucks, containers, drivers, driver portal | `Truck/Container/ContainerType/DriverController`, `DriverPortalController`, page `DriverPortal` |
| Sorting (scan-first, per-line autosave) | `SortingSessionController`, page `DonationSorting`, `resources/js/scanSounds.js` |
| Barcode / UPC / carton resolution | `UpcController`, `Services/Upc/`, `Helpers/Gtin.php`, `Models/CartonCode.php`; page `CartonCodes` ([notes/UPC_SCANNING.md](notes/UPC_SCANNING.md)) |
| Donor standing | `Services/DonorStanding.php`, `Models/DonorStandingLog.php` |

## Orders, filling and shipping ([notes/ORDERS_AND_FILLING.md](notes/ORDERS_AND_FILLING.md))

| Concern | Where |
|---|---|
| Order entry | `OrderController`, `Models/{Transaction,OrderLine}.php`, page `OrderEntry` |
| Filling, fair-share allocation, pick plan | `OrderFillingController`, `Services/PickPlanner.php`, page `OrderFilling` |
| Shipping and bill of lading | `ShippingController`, page `Shipping` |
| Status lifecycles | `Models/Status.php`, `StatusController` |

## People, accounts and permissions

| Concern | Where |
|---|---|
| People (donors, partners, staff, volunteers) | `Models/Person.php`, `PeopleController`, `PersonCategoryController`; page `People` |
| Logins, accounts, memberships | `Models/Accounts/`, `Auth/AccountUserProvider.php`, `Models/Concerns/HasAccount`, `UserAdminController`; page `Users` ([notes/MULTI_WAREHOUSE_NOTES.md](notes/MULTI_WAREHOUSE_NOTES.md)) |
| Roles and permission checks | `Models/{Role,Permission}.php`, `Http/Middleware/CheckPermission.php`, `RoleController`, `PermissionController` |
| PIN login, trusted devices | `Services/PinLoginService.php`, `PinLoginSettingsController`, `TrustedDeviceController`, page `PinLoginSettings`, `ThisDevice` |
| Volunteer kiosk, sign-in, building safety | `VolunteerSignInController`, `KioskMode/KioskSetting/BuildingSafetyController`; pages `VolunteerKiosk`, `KioskSettings`, `BuildingSafety` |
| First-run setup | `SetupController`, `Services/FirstRunService.php`, pages `Setup/FirstRun`, `Setup/Activate` ([notes/FIRST_RUN.md](notes/FIRST_RUN.md)) |

## Printing and devices ([notes/PRINT_QUEUE.md](notes/PRINT_QUEUE.md))

| Concern | Where |
|---|---|
| Print queue, printers, jobs | `Services/PrintQueueService.php`, `Models/{Printer,PrintJob}.php`, `PrintJobController`, `PrinterController`, `PrinterAdminController`; pages `PrintQueue`, `PrinterManagement` |
| Document builders (PDF via WeasyPrint) | `Services/Printing/DocumentRegistry.php`, `PrintController`, views in `resources/views/reports/` |
| Device API, heartbeats, firmware | `Device*Controller`, `ManagedDeviceController`, `DeviceReleaseController`; pages `RStationDevices`, `RStationFirmware` |
| Realtime | `Services/PrintBroadcaster.php`, `routes/channels.php` ([notes/REVERB.md](notes/REVERB.md)) |

## Reports ([notes/REPORTS.md](notes/REPORTS.md))

`InventoryReportController`, `OutstandingOrdersReportController`, `FlowReportController`, `PartnerReportController`,
`DonorReportController`, `UnfulfilledRequestsReportController`, `SortingAreaReportController`, `SitrepController`,
`MailingLabelController`, `TrainingReportController`; pages named after them (`resources/js/Pages/*Report.vue`, `SituationReport`).
Dashboards: `DashboardController`, `Services/WarehouseMetrics.php`, `Pages/Display/Stats.vue`.

## Instances, warehouses, federation, backups

| Concern | Where |
|---|---|
| Warehouse context and the "switch" tasks | `Services/Warehouses/WarehouseContext.php` (read its class comment first), `Switch*.php`, `Http/Middleware/{ResolveWarehouse,GuardWarehouseTab}.php` |
| Create, archive, remove, domains, setup codes | `Services/Warehouses/{WarehouseCreator,WarehouseRemoval,WarehouseDomains,SetupCodes,WarehouseSetup}.php`, `Console/Commands/Warehouse*.php`, `Models/Control/` |
| Instance admin UI | `InstanceWarehouseController`, `InstanceBackupController`, `InstanceUpdateController`, page `InstanceAdmin` |
| Provisioner (DB users and schemas) | `Services/Warehouses/{Provisioner,ProvisioningClient}.php`, `Console/Commands/ProvisionerWork.php`, `docker/` |
| Backups and restore | `Services/{BackupService,RestoreService,DatabaseTools,InstanceBackupService}.php`, `Console/Commands/Backup*.php`, `RestoreController`, `config/backup.php` ([notes/BACKUPS.md](notes/BACKUPS.md)) |
| Federation (identity, pairing, signed requests) | `Services/Federation/`, `Models/{Trust*,FederationIdentity}.php`, `TrustAuth` middleware, `TrustConsoleController` ([notes/FEDERATION.md](notes/FEDERATION.md)) |
| Software update | Docker: `InstanceUpdateController`, `docker/updater/`. Bare metal: `SystemController` (deprecated, see [TODO.md](TODO.md)) |
| Operator CLI | `php artisan instance:status` (overview), plus `instance:*`, `warehouse:*`, `account:*` commands |

## Training, forms, feedback, imports

| Concern | Where |
|---|---|
| Training warehouse and class tooling | `Services/{TrainingMode,TrainingPreparer,TrainingReset}.php`, `Training*Controller`, pages `Setup/Training*`, `TrainingReport` ([notes/TRAINING_WAREHOUSE.md](notes/TRAINING_WAREHOUSE.md)) |
| Public forms and submissions | `FormController`, `PublicFormController`, `FormSubmissionController`; pages `Forms`, `FormSubmissions`, `PublicForm` |
| In-app feedback reports (untrusted input) | `FeedbackReportController`, `Services/{FeedbackContentScanner,FeedbackPushService}.php` ([notes/UNTRUSTED_CONTENT.md](notes/UNTRUSTED_CONTENT.md)) |
| Data import (Flowtrac, mailing lists) | `ImportController`, `Services/Import/`, page `Imports` |
| Slow query log, system pages | `Services/SlowQueryLog.php`, `TestingToolsController`, pages `TestingTools`, `SystemAdmin` |

## Frontend ([notes/FRONTEND.md](notes/FRONTEND.md))

- `resources/js/Pages/` is 1:1 with routes. `Pages/Help/` holds the in-app guides (each also has a Blade PDF copy).
- Shared building blocks in `resources/js/Components/`: `RIForm` / `RISubform` (CRUD lists and forms; read the header comment),
  `SearchSelect`, `PrintMenu`, `QrScanner`, `HelpPage`. Layouts in `resources/js/Layouts/`.
- Admin/settings page look: the `admin-page-style` skill (`.claude/skills/admin-page-style/`).

## Database, config and tests

- Schema reference: [SCHEMA.md](SCHEMA.md) (generated). Migrations in `database/migrations/` (control, accounts and warehouse
  schemas); seeders in `database/seeders/`.
- Config: `config/` (`backup.php`, `inventory.php`, `printing.php`, `system.php`, `upc.php`, `federation.php`, `warehouses.php`).
- Tests: Pest in `tests/Feature` and `tests/Unit`; helpers `tests/SecondWarehouse.php` (a second warehouse), `tests/InstanceSchemas.php`.
  Test database setup: [TESTING.md](TESTING.md).
- Sub-projects: `launcher/`, `print-client/`, `rstation/` ([notes/SUB_PROJECTS.md](notes/SUB_PROJECTS.md)).
