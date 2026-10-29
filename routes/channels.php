<?php

use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\Broadcast;

// Every channel is inside one warehouse, `w.<id>.…` (MULTI_WAREHOUSE.md §9): one Reverb serves every warehouse, so
// each check also makes sure the channel is the current warehouse's — a prefix alone wouldn't stop a signed-in user
// asking for another warehouse's channel.
$here = fn (string $warehouse) => $warehouse === Warehouse::current()->id;

Broadcast::channel('w.{warehouse}.App.Models.User.{id}', fn ($user, $warehouse, $id) => $here($warehouse) && (int) $user->id === (int) $id);

// Print queue realtime (see App\Services\PrintBroadcaster). These carry only
// what the user could already fetch through the normal, authorised JSON API.
// Every job change — the queue screen for people who manage it.
Broadcast::channel('w.{warehouse}.print-queue', fn ($user, $warehouse) => $here($warehouse) && (bool) $user->hasPermission('manage-print-queue'));

// Printer status changes — keeps the Print menu's dots current for anyone who can print.
Broadcast::channel('w.{warehouse}.print-printers', fn ($user, $warehouse) => $here($warehouse) && (bool) $user->hasPermission('print-documents'));

// A requester's own jobs: "printed" / "failed" notices and their own queue view.
Broadcast::channel('w.{warehouse}.print-user.{id}', fn ($user, $warehouse, $id) => $here($warehouse) && (int) $user->id === (int) $id
    && $user->hasPermission('print-documents'));
