<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Something is waiting for you" — a wake-up ping to one print server, on a
 * public channel whose unguessable name is the capability (see
 * ManagedDevice::wakeChannel()). It carries nothing: the server still polls,
 * claims and downloads over its authenticated HTTP API, so a leaked channel
 * name only ever reveals that a wake-up happened.
 */
class PrintJobAvailable implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $channel) {}

    public function broadcastOn(): array
    {
        return [new Channel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'print-job.available';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
