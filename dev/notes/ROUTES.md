# Routes: how `routes/web.php` is organised

`routes/web.php` is an index. It defines no routes; it `require`s one file per area from `routes/web/`. Its header comment has the
file table. Everything below is the detail behind it.

| File | Holds |
|---|---|
| `routes/web.php` | The index and the rules. Plus `require routes/auth.php` (login, registration, password reset) at the end |
| `routes/web/*.php` | Browser pages and their `/json` endpoints, one file per area (see the table in `web.php`) |
| `routes/api.php` | Machine endpoints: devices, print clients, federation peers, the Launcher, feedback ingest. Their own auth (`TrustAuth` etc.) |
| `routes/channels.php` | Broadcast channel authorisation |
| `routes/console.php` | Console closures and the schedule |

Not every area is a single permission: a file groups by what a person is doing (receiving, orders, kiosk...), and inside it each group
carries its own gate. To find the file for a feature, use [../CODE_MAP.md](../CODE_MAP.md) or `php artisan route:list --path=<uri>`.

## Adding a route

1. **Pick the file** for the area. A page and its `/json` endpoints go together, page first. If no file fits, create
   `routes/web/<name>.php`, copy the header of a sibling (license, `namespace App\Http\Controllers;`, only the `use` lines it needs, the
   area comment), add a `require __DIR__.'/web/<name>.php';` line and a row to the table in `web.php`, and add the file to the list in this
   doc. `RouteFilesTest` fails if a file and its `require` disagree, or if `web.php` grows routes of its own.
2. **Use the same permission key for the page and its `/json` endpoints.** A page that loads for someone whose data calls would 403 is a bug.
3. **Pick the gate on purpose.**

   | Who may call it | Middleware |
   |---|---|
   | A signed-in user with a permission (the normal case) | `['auth', 'permission:<key>']`; several keys are OR: `permission:a,b` |
   | Any signed-in user | `['auth']` (or `permission:general-access` where the code already uses it) |
   | The facility kiosk, with or without a login | `kiosk-access` (`EnsureKioskAccess`); not `auth` |
   | An always-on R Station display | `display-access:<permission>,<device function>` |
   | An instance administrator | `['auth', 'instance-admin']` |
   | Anyone on the internet | nothing. Say why in a comment, and `throttle:N,1` anything that accepts input |

   New permission keys go in `database/seeders/PermissionsSeeder.php` (and a migration for existing installs); a page reached from the menu needs a
   `MenuItem` row (`permission_key` matching the route).
4. **Pages:** a closure that returns `Inertia::render('Name', ['breadcrumb' => MenuItem::getBreadcrumb('/path')])` is the house style for a page
   that only needs a breadcrumb (it needs a `MenuItem` row for that path). Once a page needs real logic, give it a controller method instead.
   `/json` endpoints always go to a controller. Vue pages live in `resources/js/Pages` and are 1:1 with page routes.
5. **Order matters inside a file.** Laravel runs the first route that matches, so a literal path must come before a `{param}` path that would also match it
   (`/orders/stock-hints` before `/orders/{id}`), or constrain the parameter (`whereNumber('id')`, `whereIn(...)`). Keep that order when you move code around.
6. **Don't register the same method and URI in two files.** Then file order can never change which route wins; `RouteFilesTest` enforces it.
7. **Names:** give a route a `->name()` only when something calls `route('...')` or `routeIs()`; most routes don't have one.
8. **No `route:cache`.** Several routes are closures, which can't be cached; don't rely on it.

## What to check after changing routes

- `php artisan route:list --json` before and after: when you only *move* routes, the set of (method, uri, name, action, middleware) must be unchanged.
- `vendor/bin/pest tests/Feature/RouteFilesTest.php` plus the tests of the area you touched (`tests/Feature/<Thing>Test.php`; see CODE_MAP).
- Pint on the files you changed (`vendor/bin/pint routes/web/<file>.php`).

## Why it is split this way

`routes/web.php` had grown to about 1,450 lines of one flat list, grouped by permission rather than by feature, so a page and its endpoints were
hundreds of lines apart and nobody could tell what a route's gate was without reading to the nearest `Route::group`. The split keeps each route's
text and order exactly as it was (verified by comparing `route:list` and first-match results before and after) and only changes where it lives.
Some permissions (`admin-system`, for one) now appear in more than one file; that is intended: the file is the feature,
the middleware is the gate.
