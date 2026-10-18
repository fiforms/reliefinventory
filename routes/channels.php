<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Print queue realtime (see App\Services\PrintBroadcaster). These carry only
// what the user could already fetch through the normal, authorised JSON API.
// Every job change — the queue screen for people who manage it.
Broadcast::channel('print-queue', fn ($user) => (bool) $user->hasPermission('manage-print-queue'));

// Printer status changes — keeps the Print menu's dots current for anyone who can print.
Broadcast::channel('print-printers', fn ($user) => (bool) $user->hasPermission('print-documents'));

// A requester's own jobs: "printed" / "failed" notices and their own queue view.
Broadcast::channel('print-user.{id}', fn ($user, $id) => (int) $user->id === (int) $id
    && $user->hasPermission('print-documents'));
