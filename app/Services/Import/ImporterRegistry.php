<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

class ImporterRegistry
{
    /** @var array<int, class-string<Importer>> */
    private const IMPORTERS = [
        FlowtracContactsImporter::class,
        FlowtracAccountsImporter::class,
        FlowtracProductsImporter::class,
        FlowtracDonationsReceivedImporter::class,
        FlowtracInventoryReconciliationImporter::class,
    ];

    /**
     * @return array<string, string> file_type => human label, for the
     *                               upload form's file-type picker.
     */
    public static function options(): array
    {
        return [
            'flowtrac_contacts' => 'Flowtrac — Contacts.csv',
            'flowtrac_accounts' => 'Flowtrac — Accounts.csv',
            'flowtrac_products' => 'Flowtrac — Products.csv',
            'flowtrac_donations_received' => 'Flowtrac — Donations Received.csv',
            'flowtrac_current_inventory' => 'Flowtrac — Current Inventory.csv (reconciliation only, never writes)',
        ];
    }

    public static function forFileType(string $fileType, ?int $actorId = null): ?Importer
    {
        foreach (self::IMPORTERS as $class) {
            $importer = new $class;
            if ($importer->fileType() === $fileType) {
                if (property_exists($importer, 'actorId')) {
                    $importer->actorId = $actorId;
                }

                return $importer;
            }
        }

        return null;
    }
}
