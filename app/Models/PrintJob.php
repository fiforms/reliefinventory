<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Services\PrintBroadcaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * A rendered PDF waiting for (or finished with) a print server. Lifecycle:
 * queued → claimed → printed | failed; failed → queued (retry);
 * claimed → queued (claim expired); queued/claimed/failed → cancelled.
 * Every status write goes through transitionTo(), which appends a
 * print_job_status_log row — the same pattern as DonationOffer.
 */
class PrintJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    private const TRANSITIONS = [
        self::STATUS_QUEUED => [self::STATUS_CLAIMED, self::STATUS_CANCELLED],
        self::STATUS_CLAIMED => [self::STATUS_PRINTED, self::STATUS_FAILED, self::STATUS_QUEUED, self::STATUS_CANCELLED],
        self::STATUS_FAILED => [self::STATUS_QUEUED, self::STATUS_CANCELLED],
    ];

    // No mass-assignable status/claim fields: those only change through
    // transitionTo()/claim code, and requested_by is set from Auth::id().
    protected $fillable = [
        'printer_id', 'kiosk_location_id', 'media', 'title', 'document', 'pdf_path', 'copies', 'requested_by',
    ];

    // Mirror the column defaults so a freshly created (unrefreshed) job
    // already reads as queued.
    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'attempts' => 0,
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function location()
    {
        return $this->belongsTo(KioskLocation::class, 'kiosk_location_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(Person::class, 'requested_by');
    }

    public function claimedByDevice()
    {
        return $this->belongsTo(ManagedDevice::class, 'claimed_by_device_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(PrintJobStatusLog::class)->orderBy('created_at')->orderBy('id');
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Move to a new status with any accompanying column updates (claim
     * fields, error_message, printed_at, ...) and append an audit row, all in
     * one transaction. Callers that race (claiming) should hold a row lock
     * (lockForUpdate) before calling so the legal-move check sees fresh state.
     */
    public function transitionTo(
        string $toStatus,
        ?int $personId = null,
        ?int $deviceId = null,
        array $columnUpdates = [],
        ?string $notes = null
    ): void {
        if (! self::canTransition($this->status, $toStatus)) {
            throw new InvalidArgumentException("Cannot move a print job from \"{$this->status}\" to \"{$toStatus}\".");
        }

        DB::transaction(function () use ($toStatus, $personId, $deviceId, $columnUpdates, $notes) {
            $from = $this->status;
            $this->status = $toStatus;
            foreach ($columnUpdates as $column => $value) {
                $this->{$column} = $value;
            }
            $this->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => $toStatus,
                'changed_by_person_id' => $personId,
                'managed_device_id' => $deviceId,
                'notes' => $notes,
            ]);
        });

        PrintBroadcaster::jobChanged($this);
    }

    /**
     * Re-route a job to another printer of the same media. Only while it is
     * waiting (queued) or has failed — a claimed job is already in a print
     * server's hands. A failed job returns to the queue on the new printer.
     */
    public function divertTo(Printer $printer, ?int $personId): void
    {
        if (! in_array($this->status, [self::STATUS_QUEUED, self::STATUS_FAILED], true)) {
            throw new InvalidArgumentException("A {$this->status} print job cannot be diverted.");
        }
        if ($printer->media !== $this->media) {
            throw new InvalidArgumentException('That printer is loaded with a different media type.');
        }

        DB::transaction(function () use ($printer, $personId) {
            $from = $this->printer?->name ?? 'unassigned';
            $note = "Diverted from {$from} to {$printer->name}";
            $this->printer_id = $printer->id;
            $this->kiosk_location_id = $printer->kiosk_location_id;

            if ($this->status === self::STATUS_FAILED) {
                $this->transitionTo(self::STATUS_QUEUED, $personId, null, [
                    'printer_id' => $printer->id,
                    'kiosk_location_id' => $printer->kiosk_location_id,
                    'error_message' => null,
                    'attempts' => 0,
                ], $note);

                return;
            }

            $this->save();
            $this->statusLogs()->create([
                'from_status' => $this->status,
                'to_status' => $this->status,
                'changed_by_person_id' => $personId,
                'notes' => $note,
            ]);
            PrintBroadcaster::jobChanged($this);
        });
    }

    /** Delete the stored PDF (after retention, or when a job is cleared). */
    public function purgeFile(): void
    {
        if ($this->pdf_path) {
            Storage::disk(config('printing.disk'))->delete($this->pdf_path);
            $this->forceFill(['pdf_path' => null])->save();
        }
    }
}
