<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Events;

use App\Models\Control\Warehouse;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A printer's status may have changed (a print server reported a different
 * queue state, came back online, or its list of queues changed). Carries no
 * data — browsers re-fetch /json/printers, which is authorised normally — so
 * the Print menu's status dots stay current.
 */
class PrintersChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function broadcastOn(): array
    {
        return [new PrivateChannel(Warehouse::current()->channel('print-printers'))];
    }

    public function broadcastAs(): string
    {
        return 'printers.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
