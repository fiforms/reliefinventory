# Relief Inventory

**Warehouse management for donated goods in disaster response.**

Relief Inventory is a free, open-source web application that helps relief organizations keep track of what
comes in, what's on the shelf, and what goes out. It was built with, and for, a disaster-response warehouse
operation, originally in cooperation with Adventist Community Services, where donations arrive in volume,
volunteers do most of the work, and the paperwork has to keep up with the trucks.

- **Receive** a donation at the dock: log the donor, driver and what arrived, take a photo, and print a barcode
  label for every container. Track donations offered by phone before they ship.
- **Sort** it. Scan a pallet's label and enter what's inside, by keyboard, scanner or phone camera. Each item is
  recorded as usable, outdated, trashed or diverted, and only usable goods count as stock.
- **Know what you have.** An append-only ledger of every movement is the source of truth for stock-on-hand,
  pallet locations and inventory reports.
- **Fill requests.** Partner agencies request what they need. Staff fill requests from stock on paper pick sheets
  or by scanning at the shelf, with a suggested fair split when there isn't enough to go around.
- **Ship and deliver.** Move filled requests through shipping and delivery, with a bill of lading.
- **Everything else:** role-based permissions, several warehouses on one instance, federation between instances,
  a volunteer and guest sign-in kiosk, a print queue that sends labels and reports to the right printer, reports
  with CSV and PDF output, and in-app issue reporting.

Stock is pooled by item type once sorted, so a shelf item can't be traced back to a specific donor or pallet;
this is not recall-style tracing.

More at the project [website](https://reliefinventory.app). Release history is in
[CHANGELOG.md](CHANGELOG.md).

## Quick start (Docker)

The easiest way to run Relief Inventory is the prebuilt Docker image (`amd64` and `arm64`). It includes the
database and everything else, so you only need Docker.
**New to Docker? Follow the step-by-step guide: [docker/INSTALL.md](docker/INSTALL.md)**, for Windows, Mac and
Linux using Docker Desktop.

If you already use Docker:

```bash
curl -O https://raw.githubusercontent.com/fiforms/reliefinventory/master/docker/compose.yaml
# set APP_URL at the top of compose.yaml to the address people will type into a browser
docker compose up -d
```

Then open `APP_URL` (default <http://localhost:8080>); a setup wizard creates your administrator account. To
upgrade, run `docker compose pull && docker compose up -d`.

**Updating from the web page (optional, Linux hosts):** instead of running the upgrade by hand, an instance
administrator can click *Check for Updates* / *Install Update* on the **Updates & Backups** page. That needs one
small service on the host; see [Automatic updates](docker/README.md#automatic-updates-optional) and the setup steps
in [docker/INSTALL.md](docker/INSTALL.md#optional-update-from-the-web-page-linux-server).

Putting it on the internet? Read [docker/README.md](docker/README.md) first. It covers HTTPS, email, backups and
restore, and the setup code that stops a stranger from claiming the administrator account before you do.

## Companion apps

Two optional pieces extend the web app to the warehouse floor. Downloads are on the
[Releases](https://github.com/fiforms/reliefinventory/releases) page.

- **R Station** is a Raspberry Pi device image: a warehouse kiosk and print server that can also run a whole
  offline site with no Internet. Pair it from **Setup > Devices**. See [rstation/](rstation/).
- **RI Print** is a desktop print server for Windows, macOS and Linux. Install it on a computer with a printer, pair
  it once with a 10-character code, and its printers appear in the web app's **Print** menu. See
  [print-client/README.md](print-client/README.md).

## Building from source

Building from source, running a development instance and running the tests are covered in
[BUILDING.md](dev/BUILDING.md). The architecture and conventions are in
[dev/README.md](dev/README.md) (doc map) and [CLAUDE.md](CLAUDE.md).

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
