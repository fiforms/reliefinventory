<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Events;

use App\Models\Control\Warehouse;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A print job was created or changed status. Goes to two private channels:
 * `print-queue` (manage-print-queue sees every job) and the requester's own
 * `print-user.{id}` — so the queue screen refreshes instantly and the person
 * who printed hears "printed" / "failed". Sent immediately rather than queued,
 * so no queue worker is needed; see App\Services\PrintBroadcaster.
 */
class PrintJobChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public array $job, public ?int $requestedBy) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel(Warehouse::current()->channel('print-queue'))];
        if ($this->requestedBy) {
            $channels[] = new PrivateChannel(Warehouse::current()->channel('print-user.'.$this->requestedBy));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'print-job.changed';
    }

    public function broadcastWith(): array
    {
        return $this->job;
    }
}
