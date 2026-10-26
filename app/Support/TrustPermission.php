<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * What a trusted peer may do. Worded from the peer acting on us: an inbound grant of `feedback.send` lets
 * the peer send feedback to this instance. Only the ones marked built() have endpoints yet; the rest are
 * defined so trusts can already carry them and the console can describe the risk.
 */
enum TrustPermission: string
{
    case PrintShare = 'print.share';
    case FeedbackSend = 'feedback.send';
    case FeedbackReceive = 'feedback.receive';
    case OffersSend = 'offers.send';
    case OffersReceive = 'offers.receive';
    case InventoryQueryExact = 'inventory.query_exact';
    case SuppliesRequest = 'supplies.request';
    case SuppliesQueryAvailable = 'supplies.query_available';
    case OperatorFullRead = 'operator.full_read';

    public function label(): string
    {
        return match ($this) {
            self::PrintShare => 'Sharing printers',
            self::FeedbackSend => 'Sending feedback',
            self::FeedbackReceive => 'Receiving feedback',
            self::OffersSend => 'Send donation offers',
            self::OffersReceive => 'Receive donation offers',
            self::InventoryQueryExact => 'Query exact inventory',
            self::SuppliesRequest => 'Request supplies',
            self::SuppliesQueryAvailable => 'Query items available for request',
            self::OperatorFullRead => 'Backup and reporting operator (full read access)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PrintShare => 'The peer is a print server (or may use printers shared with it) and may claim print jobs.',
            self::FeedbackSend => 'The peer may send bug and feature reports to this instance.',
            self::FeedbackReceive => 'The peer may read the feedback reports this instance holds.',
            self::OffersSend => 'The peer may offer donations to this instance.',
            self::OffersReceive => 'The peer may receive donation offers from this instance.',
            self::InventoryQueryExact => 'The peer may read exact stock quantities.',
            self::SuppliesRequest => 'The peer may submit supply requests, which staff review like any other request.',
            self::SuppliesQueryAvailable => 'The peer may see which items are available to request.',
            self::OperatorFullRead => 'The peer may read everything, including backups. A database backup holds credentials and personal data: grant this only to an operator you would trust with the whole system.',
        };
    }

    /** low | medium | high — how much a peer holding it could see or do. */
    public function risk(): string
    {
        return match ($this) {
            self::PrintShare, self::FeedbackSend, self::OffersSend => 'low',
            self::FeedbackReceive, self::OffersReceive, self::SuppliesRequest, self::SuppliesQueryAvailable => 'medium',
            self::InventoryQueryExact, self::OperatorFullRead => 'high',
        };
    }

    /** Whether an endpoint enforcing this exists yet. */
    public function built(): bool
    {
        return in_array($this, [self::PrintShare, self::FeedbackSend], true);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function catalog(): array
    {
        return array_map(fn (self $p) => [
            'key' => $p->value, 'label' => $p->label(), 'description' => $p->description(),
            'risk' => $p->risk(), 'built' => $p->built(),
        ], self::cases());
    }
}
