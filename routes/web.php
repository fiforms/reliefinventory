<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

/*
 * Web routes: the entry point only. Do not define routes here.
 *
 * Browser pages and their /json endpoints live in one file per area under routes/web/. Add a route to the file for its area (table
 * below), next to the page it serves. If nothing fits, add a new file AND a `require` line below; a test fails if the two disagree.
 * The full rules, with examples, are in dev/notes/ROUTES.md. The short version:
 *
 *  - A page and its /json endpoints use the same permission key, and sit together in the same file.
 *  - Every route is gated on purpose: `auth` + `permission:<key>`, or a named exception (`kiosk-access`, `display-access`,
 *    `instance-admin`, `throttle`d public routes). A route with no gate is public; say why in a comment.
 *  - Laravel matches the first route that fits. Within a file, put literal paths before `{param}` paths (or constrain the
 *    parameter with whereNumber()/whereIn()). The files are required in the order below; no URI is defined in two files, so the order
 *    between files does not matter today. Keep it that way.
 *  - Machine endpoints (devices, print clients, federation peers, the Launcher) go in routes/api.php; login, registration and password
 *    reset are in routes/auth.php; broadcast channels in routes/channels.php.
 *
 *   web/setup.php       first-run wizard, warehouse activation, restore, invitations, manifest
 *   web/public.php      pages with no login: welcome, driver portal, device handoff, display screens
 *   web/account.php     any signed-in user: dashboard, profile, lookups, notifications, feedback
 *   web/help.php        help guides and their PDFs, warehouse signs
 *   web/orders.php      order entry, filling, shipping, order documents
 *   web/receiving.php   receiving, donation offers, trucks, containers, streams
 *   web/sorting.php     donation sorting and barcode lookup
 *   web/inventory.php   pallets, stock, locations, warehouses, pallet labels
 *   web/catalog.php     items, item types, categories, units, package types, uses
 *   web/people.php      people, users, roles, counties
 *   web/reports.php     report pages, report data, PDF/CSV downloads, dashboards
 *   web/kiosk.php       volunteer kiosk, building safety, kiosk settings, PIN login, trusted devices
 *   web/printing.php    print queue and print jobs
 *   web/devices.php     R Station devices, printer setup, firmware
 *   web/forms.php       custom forms, submissions, design votes
 *   web/training.php    training warehouse tools and trainee sign-up
 *   web/system.php      warehouse administration: backups, email, sessions, testing, feedback, import
 *   web/instance.php    instance administration (whole-instance backups, warehouse management)
 *   web/federation.php  instance identity document and the trust console
 */

require __DIR__.'/web/setup.php';
require __DIR__.'/web/public.php';
require __DIR__.'/web/account.php';
require __DIR__.'/web/help.php';
require __DIR__.'/web/orders.php';
require __DIR__.'/web/receiving.php';
require __DIR__.'/web/sorting.php';
require __DIR__.'/web/inventory.php';
require __DIR__.'/web/catalog.php';
require __DIR__.'/web/people.php';
require __DIR__.'/web/reports.php';
require __DIR__.'/web/kiosk.php';
require __DIR__.'/web/printing.php';
require __DIR__.'/web/devices.php';
require __DIR__.'/web/forms.php';
require __DIR__.'/web/training.php';
require __DIR__.'/web/system.php';
require __DIR__.'/web/instance.php';
require __DIR__.'/web/federation.php';

require __DIR__.'/auth.php';
