# Building and running from source

Most people should [run the Docker image](../README.md#quick-start-docker) instead. This page is for
developers, and for anyone installing on a bare server without Docker.

Relief Inventory is a [Laravel](https://laravel.com/) 11 + Inertia.js + Vue 3 application. For an architecture
tour, see [dev/README.md](README.md) and [CLAUDE.md](../CLAUDE.md); for known gaps and the roadmap, [PROJECT_ANALYSIS.md](notes/PROJECT_ANALYSIS.md).

## Requirements

- PHP 8.4 with the usual Laravel extensions (gd, intl, zip, xml, mysql, bcmath, exif)
- Composer, Node.js and npm
- MariaDB (or MySQL)
- [WeasyPrint](https://weasyprint.org/) on the `PATH` — every PDF (pallet labels, reports, order forms, pick
  sheets) is rendered with it. Without it the app runs but PDF generation fails.
- Optional: Redis, for a production cache

On Debian/Ubuntu:

    sudo apt install php php-cli composer mariadb-server php-mysql php-redis php-gd php-xml php-intl php-zip \
        php-bcmath git npm weasyprint

## Setup

**Clone the repository**

    git clone https://github.com/fiforms/reliefinventory
    cd reliefinventory

**Create a database** (change `YourPasswordHere`)

    sudo mariadb
    create user reliefinventory@localhost identified by 'YourPasswordHere';
    create database reliefinventory;
    create database reliefinventory_control;
    create database reliefinventory_accounts;
    grant all on reliefinventory.* to reliefinventory@localhost;
    grant all on `reliefinventory\_control`.* to reliefinventory@localhost;
    grant all on `reliefinventory\_accounts`.* to reliefinventory@localhost;
    exit

The `_control` and `_accounts` schemas hold the instance's list of warehouses and its login accounts
(see `MULTI_WAREHOUSE.md`); they are named after the main database (`RI_CONTROL_DATABASE` /
`RI_ACCOUNTS_DATABASE` override that). The Docker image creates them itself.

**Create your local configuration** and edit `.env` with the database name and password from above

    cp .env.example .env
    nano .env

**Install dependencies**

    composer install
    php artisan key:generate
    npm install

**Create the tables**

*(If you have a database backup, restore it at this point instead.)*

    php artisan instance:migrate

**Finish setup in the browser**

Start the app (`composer run dev`, or your web server) and open it in a browser. A first-run setup wizard asks
for the administrator's name, email and password, seeds the essential data (statuses, units, categories, item
catalog, permissions) and optionally loads example data (fictional donations, pallets and orders at every
lifecycle stage — demo instances only, never a real operation). It closes for good once used.

If the server is reachable from the internet before you finish, set `RI_SETUP_CODE` in `.env` so completing the
wizard also requires that code (otherwise whoever opens the page first becomes the administrator).

Prefer the command line? These do the same job without the wizard:

    php artisan setup:seed-essentials          # essential data; no-op if already seeded
    php artisan user:create                    # interactive; or, non-interactively:
    php artisan admin:bootstrap --email=you@example.com [--password=...]   # random password printed if omitted
    php artisan db:seed --class=WarehouseActivitySeeder   # optional demo data

## Running a development instance

    composer run dev

This starts the web server, queue listener, log viewer and Vite together. `npm run dev` runs Vite alone.

## Tests

    vendor/bin/pest

The suite runs against a separate, disposable database — never your real one. See
[scripts/TESTING.md](../scripts/TESTING.md) before changing that configuration or setting up a new machine. PHP
style is checked with `vendor/bin/pint`.

## Updating

After pulling new code you may need some or all of:

    composer install
    npm install
    npm run build
    php artisan instance:migrate

## Running in production

(These steps are still incomplete. See also [scripts/](../scripts/) for the update, backup and realtime notes.)

**Web server and cache.** You'll need a web server such as Apache or nginx with PHP, and optionally Redis.

    sudo apt install apache2 redis

**SMTP.** Point these `.env` values at a working mail server (for example, an app password relayed through
Gmail):

    MAIL_MAILER=smtp
    MAIL_HOST=smtp.gmail.com
    MAIL_PORT=587
    MAIL_USERNAME=my_email_address@example.com
    MAIL_PASSWORD=apppasswordhere
    MAIL_ENCRYPTION=tls
    MAIL_FROM_ADDRESS="my_email_address@example.com"
    MAIL_FROM_NAME="${APP_NAME}"

**Cloudflare Turnstile for user registration.** Get a site key and secret key from
[Cloudflare](https://www.cloudflare.com/application-services/products/turnstile/) and put them in `.env`:

    CLOUDFLARE_TURNSTILE_SITE_KEY=
    CLOUDFLARE_TURNSTILE_SECRET_KEY=

**Ban signups from disposable email domains.** Download the list from
[this GitHub project](https://github.com/disposable-email-domains/disposable-email-domains), then:

    php artisan user:import-banned /path/to/disposable_email_blocklist.conf

**Import the list of counties.** A combined, ready-to-import file for all US states and territories is bundled at
`database/data/national_counties_2020.txt` (census.gov only publishes it as 57 separate per-state files now, so
this is those concatenated). Re-running the import is safe: existing counties are updated in place, not
duplicated.

    php artisan counties:import database/data/national_counties_2020.txt

To refresh with newer data, download the per-state files linked from
[census.gov](https://www.census.gov/library/reference/code-lists/ansi.html#cou) (pipe-delimited,
`STATE|STATEFP|COUNTYFP|COUNTYNS|COUNTYNAME|CLASSFP|FUNCSTAT`) and concatenate them before importing.

**Recompile the project.**

    composer dump-autoload --optimize
    npm run build

## Building the Docker image

    docker build -t reliefinventory .
    docker buildx build --platform linux/amd64,linux/arm64 -t reliefinventory .   # both architectures (needs buildx)

Published images are built by the **Docker image (multi-arch)** GitHub Action (Actions tab, Run workflow), which
builds both architectures natively and publishes `ghcr.io/fiforms/reliefinventory`. See
[docker/README.md](../docker/README.md) for running it and [scripts/DOCKER_PLAN.md](../scripts/DOCKER_PLAN.md) for the
design.

## Building the companion apps

- **RI Print** (desktop print server): see [print-client/README.md](../print-client/README.md). Built for
  Windows, macOS and Linux by the `Print client` GitHub Actions workflows.
- **R Station** (Raspberry Pi device image): see [rstation/IMPLEMENTATION.md](../rstation/IMPLEMENTATION.md).
