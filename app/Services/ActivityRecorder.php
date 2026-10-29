<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DonationOfferStatusLog;
use App\Models\Driver;
use App\Models\ItemLedger;
use App\Models\PalletStatus;
use App\Models\PalletStockAssignment;
use App\Models\Person;
use App\Models\Status;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Who did what, at which station — recorded in training warehouses only (MULTI_WAREHOUSE.md §12), where the report cards read it. Hooks the model events of the records
 * each station writes, so every path that writes them is covered without touching the station controllers:
 *
 *   1 Receiving  — a donation created as Received/Logged; a donation offer logged or decided
 *   2 Sorting    — a sorted ledger row; a donation moved to Complete
 *   3 Inventory  — goods put on a W pallet; a W pallet moved, sealed, opened or emptied
 *   4 Picking    — a fill row; an order moved to Filling / Filled / Closed - Unfulfilled
 *   5 Shipping   — an order moved to Ready to Ship (driver assigned) / Shipped
 *   6 Delivery / Ordering — an order created or submitted (Ready to Fill); a signed BOL uploaded (Delivered,
 *                  credited to the Driver Portal driver when no one is logged in); a BOL approved or rejected
 *
 * The actor is the logged-in person; with nobody logged in (artisan, the simulator) nothing is recorded, except a
 * Driver Portal delivery (the portal's driver session). Prepare Training's own writes are skipped via paused(). Recording never throws: a log
 * failure is reported and the station's own write goes ahead.
 */
class ActivityRecorder
{
    private static bool $paused = false;

    public static function register(): void
    {
        Transaction::created(function (Transaction $t) {
            if (! self::active()) {
                return;
            }
            $status = self::statusName($t->status_id);
            if ($t->type === 'donation' && in_array($status, [Transaction::STATUS_RECEIVED, Transaction::STATUS_LOGGED], true)) {
                self::record('receiving.intake', $t);
            } elseif ($t->type === 'order') {
                self::record('ordering.request_created', $t);
            }
        });

        Transaction::updated(function (Transaction $t) {
            if (! $t->wasChanged('status_id') || ! self::active()) {
                return;
            }
            $action = match ([$t->type, self::statusName($t->status_id)]) {
                ['donation', Transaction::STATUS_COMPLETE] => 'sorting.completed',
                ['order', Transaction::STATUS_READY_TO_FILL] => 'ordering.request_submitted',
                ['order', Transaction::STATUS_FILLING] => 'picking.started',
                ['order', Transaction::STATUS_FILLED] => 'picking.completed',
                ['order', Transaction::STATUS_CLOSED_UNFULFILLED] => 'picking.completed',
                ['order', Transaction::STATUS_READY_TO_SHIP] => 'shipping.driver_assigned',
                ['order', Transaction::STATUS_SHIPPED] => 'shipping.shipped',
                ['order', Transaction::STATUS_DELIVERED] => 'delivery.bol_uploaded',
                ['order', Transaction::STATUS_COMPLETED] => 'delivery.bol_approved',
                ['order', Transaction::STATUS_DELIVERED_AWAITING_BOL] => 'delivery.bol_rejected',
                default => null,
            };
            if ($action === 'delivery.bol_uploaded' && ! Auth::check()) {
                self::record($action, $t, self::portalDriverId());
            } elseif ($action) {
                self::record($action, $t);
            }
        });

        ItemLedger::created(function (ItemLedger $row) {
            if (! self::active()) {
                return;
            }
            if ($row->order_line_id) {
                self::record('picking.fill', $row);
            } elseif (($row->pallet_id || $row->disposition) && ! $row->stock_pallet_id
                && Transaction::where('id', $row->orderdonation_id)->value('type') === 'donation') {
                self::record('sorting.line', $row);
            }
        });

        PalletStockAssignment::created(fn (PalletStockAssignment $a) => $a->qty > 0 ? self::record('inventory.put_on_pallet', $a) : null);

        PalletStatus::created(function (PalletStatus $status) {
            if (self::active() && $status->pallet?->kind === 'W') {
                self::record('inventory.pallet_'.str($status->status ?: 'update')->snake()->limit(30, ''), $status);
            }
        });

        DonationOfferStatusLog::created(fn (DonationOfferStatusLog $log) => self::record('receiving.offer_'.$log->to_status, $log));
    }

    /** Run $callback without recording anything (Prepare Training's starter data isn't anyone's work). */
    public static function paused(callable $callback): mixed
    {
        $was = self::$paused;
        self::$paused = true;
        try {
            return $callback();
        } finally {
            self::$paused = $was;
        }
    }

    /** Someone to credit: a logged-in person, or a Driver Portal driver. */
    private static function active(): bool
    {
        return ! self::$paused && TrainingMode::active() && (Auth::check() || self::portalDriverId());
    }

    private static function portalDriverId(): ?int
    {
        return app()->bound('request') && request()->hasSession()
            ? ((int) request()->session()->get('driver_id') ?: null)
            : null;
    }

    public static function stationFor(string $action): ?int
    {
        return match (strtok($action, '.')) {
            'receiving' => 1,
            'sorting' => 2,
            'inventory' => 3,
            'picking' => 4,
            'shipping' => 5,
            'ordering', 'delivery' => 6,
            default => null,
        };
    }

    private static function record(string $action, Model $subject, ?int $driverId = null): void
    {
        if (self::$paused || ! TrainingMode::active()) {
            return;
        }
        try {
            $personId = Auth::id();
            if (! $personId && ! $driverId) {
                return;
            }
            $team = $personId
                ? Person::whereKey($personId)->where('is_trainee', true)->value('training_team')
                : Driver::whereKey($driverId)->value('training_team');

            ActivityLog::create([
                'person_id' => $personId,
                'driver_id' => $personId ? null : $driverId,
                'training_team' => $team,
                'station' => self::stationFor($action),
                'action' => $action,
                'subject_type' => class_basename($subject),
                'subject_id' => $subject->getKey(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function statusName(?int $statusId): ?string
    {
        if (! $statusId) {
            return null;
        }

        // Not cached in a static: ids are only stable within one database (tests reuse them across runs).
        return Status::whereKey($statusId)->value('name');
    }
}
