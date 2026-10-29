<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;

/**
 * The Docker self-update flag: a single row in the control schema. The app cannot run `docker compose` itself, so an instance
 * admin only *requests* a check or an update here; the updater on the host (docker/updater/ri-updater.py) polls
 * `GET /api/instance/update` with the shared secret `RI_UPDATE_SECRET`, does the work, and reports each step back to
 * `POST /api/instance/update/status`. The same shape as the provisioner's command queue, with the worker outside Docker.
 */
class InstanceUpdate extends Model
{
    public $timestamps = false;

    protected $connection = 'control';

    protected $table = 'instance_update';

    public const ACTIONS = ['check', 'update'];

    /** What the updater may report. `done` and `failed` end a run. */
    public const STATES = ['checking', 'pulling', 'restarting', 'done', 'failed'];

    /** A request or run that has not finished. */
    public const IN_PROGRESS = ['requested', 'checking', 'pulling', 'restarting'];

    /** A run that has not changed state for this long is given up on (the updater died, or was never installed) and can be requested again. */
    public const STALE_AFTER_MINUTES = 30;

    protected $fillable = ['action', 'state', 'requested_by', 'requested_at', 'message', 'current_version', 'available_version', 'last_checked_at', 'state_changed_at'];

    protected $casts = ['requested_at' => 'datetime', 'last_checked_at' => 'datetime', 'state_changed_at' => 'datetime'];

    public static function current(): self
    {
        return self::query()->first() ?? self::create(['state' => 'idle']);
    }

    /** The feature is off (and the endpoints 404) until the compose file sets RI_UPDATE_SECRET. */
    public static function enabled(): bool
    {
        return (string) config('system.update_secret') !== '';
    }

    public function inProgress(): bool
    {
        return in_array($this->state, self::IN_PROGRESS, true) && ! $this->stale();
    }

    public function stale(): bool
    {
        return in_array($this->state, self::IN_PROGRESS, true)
            && $this->state_changed_at !== null
            && $this->state_changed_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /** Queue a request. Null (nothing written) when a run is already going. */
    public static function request(string $action, string $by): ?self
    {
        return self::getConnectionResolver()->connection('control')->transaction(function () use ($action, $by) {
            $row = self::query()->lockForUpdate()->first() ?? self::create(['state' => 'idle']);
            if ($row->inProgress()) {
                return null;
            }
            $row->update([
                'action' => $action, 'state' => 'requested', 'requested_by' => $by, 'requested_at' => now(),
                'state_changed_at' => now(), 'message' => null,
            ]);
            InstanceAuditLog::record('update.requested', null, ['action' => $action], $by);

            return $row;
        });
    }

    /** The updater's report. Ignored (false) unless a run is open, so a late or repeated report cannot reopen or rewrite a finished one. */
    public function report(string $state, ?string $message = null, ?string $current = null, ?string $available = null): bool
    {
        if (! in_array($this->state, self::IN_PROGRESS, true)) {
            return false;
        }
        $finished = in_array($state, ['done', 'failed'], true);
        $action = $this->action;
        $this->fill([
            'state' => $state, 'message' => $message, 'state_changed_at' => now(),
            'current_version' => $current ?? $this->current_version,
            'action' => $finished ? null : $this->action,
        ]);
        if ($action === 'check' && $state === 'done') {
            $this->fill(['available_version' => $available, 'last_checked_at' => now()]);
        } elseif ($action === 'update' && $state === 'done') {
            // Up to date now, whatever a check said before.
            $this->available_version = null;
        }
        $this->save();
        if ($finished) {
            InstanceAuditLog::record('update.finished', null, array_filter([
                'action' => $action, 'result' => $state, 'message' => $message, 'current_version' => $this->current_version,
                'available_version' => $this->available_version,
            ]), $this->requested_by);
        }

        return true;
    }

    /** What the admin page and `instance:update status` show. */
    public function payload(): array
    {
        return [
            'enabled' => self::enabled(),
            'action' => $this->action,
            'state' => $this->stale() ? 'stalled' : $this->state,
            'message' => $this->stale() ? 'The updater stopped reporting. Is it running on the host?' : $this->message,
            'requested_by' => $this->requested_by,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'current_version' => $this->current_version,
            'available_version' => $this->available_version,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
        ];
    }
}
