# Relief Inventory

**Warehouse management for donated goods in disaster response.**

Relief Inventory is a free, open-source web application that helps relief organizations keep track of what
comes in, what's on the shelf, and what goes out. It was built with, and for, a disaster-response warehouse
operation, originally in cooperation with Adventist Community Services, where donations arrive in volume and
unpredictably, volunteers do most of the work, and the paperwork has to keep up with the trucks.

A donation's life in the system:

- **Receive** a donation at the dock: log the donor, the driver and what arrived (pallets, boxes, bags, totes),
  take a photo, and print a barcode label for every container. A donor who isn't known yet can be flagged for
  follow-up without holding anything up.
- **Sort** it. Scan a pallet's label and enter what's inside, item by item, by keyboard or camera. Each item is
  recorded with its condition (usable, outdated, trashed or diverted), and only usable goods count as stock.
- **Know what you have.** A ledger of every movement is the source of truth, with stock-on-hand and inventory
  reports computed from it.
- **Take requests and fill them.** Partner agencies request what they need. Staff fill those requests from
  stock, on paper pick sheets or by scanning at the shelf, and the ledger is updated as items are picked.
  When there isn't enough to go around, a review panel suggests a fair split.
- **Offers and visitors.** Track donations offered by phone before they ship, and sign volunteers and guests in
  on a kiosk.

It also handles role-based permissions, a print queue that sends labels and reports to the right printer at each
site, an in-app way for users to report problems, and an outstanding-orders report. Some things aren't built
yet. The order lifecycle past "Filled" (ready to ship, shipped, and uploading a bill of lading) and several
reports are still open; [PROJECT_ANALYSIS.md](PROJECT_ANALYSIS.md) keeps the honest list.

Stock is pooled by item type once it's sorted, so a shelf item can't be traced back to a specific donor or
pallet. Source is recorded when donations are sorted, but it isn't recall-style item tracing.

More at the project [website](https://reliefinventory.fiforms.net).

## Quick start (Docker)

The easiest way to run Relief Inventory is the prebuilt Docker image, published for both `amd64` (PCs,
servers) and `arm64` (Raspberry Pi, Apple silicon, ARM cloud servers). You need only
[Docker](https://docs.docker.com/get-docker/) with the Compose plugin. Nothing else is installed on the host:
the database, PHP and the PDF renderer are all part of the stack.

```bash
curl -O https://raw.githubusercontent.com/fiforms/reliefinventory/master/docker/compose.yaml
```

1. Open `compose.yaml` and set `APP_URL` in the "YOUR SETTINGS" block at the top to the address people will type
   into a browser. The default, `http://localhost:8080`, is fine for trying it out on the same machine.
2. Start it:
   ```bash
   docker compose up -d
   ```
3. Open `APP_URL` in a browser. A setup wizard asks for the administrator's name, email and password, and
   whether to load example data. Then you're signed in.

On first start the stack creates its own database, generates random database passwords, runs the migrations and
loads the essential lookup data. A daily database dump is written to a `backups/` folder next to
`compose.yaml`.

**To upgrade:**

```bash
docker compose pull
docker compose up -d
```

Putting it on the internet? Read [docker/README.md](docker/README.md) first. It covers HTTPS behind a reverse
proxy, email, backups and restore, the optional realtime service, and the setup code that stops a stranger from
claiming the administrator account before you do.

## Companion apps

The web app works on its own, but two optional pieces extend it to the warehouse floor. Prebuilt downloads will
be published on the project's [GitHub Releases](https://github.com/fiforms/reliefinventory/releases) page.

- **R Station** is a Raspberry Pi device image. A Pi running it works as a standalone kiosk for tasks in the
  warehouse, booting straight into a dedicated screen for your server, and doubles as a print server, so labels
  and reports print on a printer at the dock or in the office. You pair it with your server once and manage it
  from **Setup > Devices**. Source and notes are in [rstation/](rstation/). *Planned, not yet implemented:* the
  R Station acting as a local Docker host, so a site could keep running Relief Inventory from the Pi in an
  emergency with no Internet connection.
- **RI Print** is a desktop print server for Windows, macOS and Linux. Install it on any computer that has a
  printer attached, pair it once with a 6-digit code, and that computer's printers show up in the web app's
  **Print** menu. It does the same job as the R Station's print server without any special hardware. See
  [print-client/README.md](print-client/README.md).

## Building from source

Building from source, running a development instance and running the tests are covered in
[BUILDING.md](BUILDING.md). The architecture, conventions and current state of the code are in
[CLAUDE.md](CLAUDE.md) and [PROJECT_ANALYSIS.md](PROJECT_ANALYSIS.md).

# License

## Relief Inventory - Open Source Software

### Copyright (C) 2024-2025 Adventist Community Services
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
    
    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.

## Original Authors
This software was originally designed and written by "The Preacher Hackers," a group of Seventh-day Adventist Ministers dedicated to the cause of helping those in need. Original contributing authors include [Daniel McFeeters](https://www.pastordaniel.net), Mark Kent, and Jonathan Cantrell.

## Purpose and Rationale
The **Relief Inventory** project is an open-source software system designed to facilitate the management of donated goods in disaster relief efforts. It allows organizations to efficiently track, store, and distribute donated items to those in need. The goal of this project is to provide a **transparent, accessible, and collaborative** solution that enhances humanitarian aid operations.

To ensure that this software remains open and freely available to all, the project is licensed under the **GNU General Public License v3.0**. This license ensures that:
- The software remains **free and open-source** for anyone to use, modify, and distribute.
- Any modifications or derivative works are also **licensed under GPL-3.0**, preserving openness.
- Users have the **freedom to improve** the software while contributing back to the community.

Please see [LICENSE.md](LICENSE.md) for details

## Attributions, Disclaimers and Additional Free Licenses
Much of the code in this project has been generated by the "artisan" command in [Laravel](https://laravel.com/), with modifications made to fit the requirements of this project. Additional code has been generated or modified through the use of Large Language Models such as ChatGPT in order to accelerate the development process. However, it is the belief of the developers that such use of existing tools should not influence the licensing terms set forth above.
