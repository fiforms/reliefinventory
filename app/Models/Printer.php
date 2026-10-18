<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One physical printer, as an OS/CUPS queue on a print server (a
 * ManagedDevice). A single print server owns many of these, each with its own
 * media and site. Rows are auto-created (inactive, untyped) when a server's
 * heartbeat reports a new queue, and never deleted when a queue disappears —
 * the queue just reads as unavailable.
 */
class Printer extends Model
{
    public const STATE_IDLE = 'idle';

    public const STATE_PRINTING = 'printing';

    public const STATE_ERROR = 'error';

    public const STATE_OFFLINE = 'offline';

    // Derived (not stored): the server is offline/unreachable, or the queue
    // is no longer in its latest report.
    public const STATE_UNAVAILABLE = 'unavailable';

    protected $fillable = ['name', 'kiosk_location_id', 'media', 'active', 'sort_order'];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected $appends = ['status', 'status_message', 'media_label'];

    public function device()
    {
        return $this->belongsTo(ManagedDevice::class, 'managed_device_id');
    }

    public function location()
    {
        return $this->belongsTo(KioskLocation::class, 'kiosk_location_id');
    }

    public function jobs()
    {
        return $this->hasMany(PrintJob::class);
    }

    /** The queue's entry in its server's latest heartbeat snapshot, or null. */
    private function reportedQueue(): ?array
    {
        foreach ($this->device?->reported_printers ?? [] as $queue) {
            if (($queue['queue_name'] ?? null) === $this->queue_name) {
                return $queue;
            }
        }

        return null;
    }

    /**
     * idle|printing|error|offline as the server reports it, or unavailable
     * when the server itself is offline/revoked or no longer lists the queue.
     * Computed on read — the app has no scheduler to flip a stored column.
     */
    public function getStatusAttribute(): string
    {
        if (! $this->device || $this->device->revoked || ! $this->device->online) {
            return self::STATE_UNAVAILABLE;
        }
        $queue = $this->reportedQueue();
        if (! $queue) {
            return self::STATE_UNAVAILABLE;
        }

        return in_array($queue['state'] ?? null, [self::STATE_IDLE, self::STATE_PRINTING, self::STATE_ERROR, self::STATE_OFFLINE], true)
            ? $queue['state']
            : self::STATE_IDLE;
    }

    public function getStatusMessageAttribute(): ?string
    {
        if ($this->status === self::STATE_UNAVAILABLE) {
            return $this->device && ! $this->device->online ? 'Print server is offline' : 'Queue not reported by print server';
        }

        return $this->reportedQueue()['state_message'] ?? null;
    }

    /** Healthy enough that a job sent now will print promptly. */
    public function isReady(): bool
    {
        return in_array($this->status, [self::STATE_IDLE, self::STATE_PRINTING], true);
    }

    public function getMediaLabelAttribute(): ?string
    {
        return $this->media ? (config("printing.media.{$this->media}.label") ?? $this->media) : null;
    }

    /** Active, typed, and assigned to a site — i.e. offerable in a Print-to menu. */
    public function scopeUsable($query)
    {
        return $query->where('active', true)->whereNotNull('media')->whereNotNull('kiosk_location_id');
    }
}
