<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Printing;

use App\Http\Controllers\DonorReportController;
use App\Http\Controllers\FlowReportController;
use App\Http\Controllers\HelpReportController;
use App\Http\Controllers\InventoryLabelController;
use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\MailingLabelController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderFillingController;
use App\Http\Controllers\OutstandingOrdersReportController;
use App\Http\Controllers\PalletReportController;
use App\Http\Controllers\PartnerReportController;
use App\Http\Controllers\SitrepController;
use App\Http\Controllers\TrainingAdminController;
use App\Http\Controllers\TrainingReportController;
use App\Http\Controllers\UnfulfilledRequestsReportController;
use App\Models\LabelSetting;
use App\Services\TrainingMode;
use App\Services\WarehouseMetrics;
use Closure;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Everything that can be sent to a printer. Each entry reuses the same
 * *Builder() method its browser download route renders from, so a printed
 * copy and a downloaded copy can never drift apart.
 *
 *  - media:      key into config('printing.media'); only printers loaded
 *                with that media are offered/accepted for this document.
 *                (Pallet labels and inventory labels use the media chosen in LabelSetting.)
 *  - permission: the permission the document's own download route requires
 *                (null = any signed-in user, matching routes that are
 *                `auth` only). Sending to a printer never grants more
 *                access than downloading the same PDF would.
 *  - rules:      validation for the request's `params`.
 *  - title:      what the queue shows for the job.
 *  - build:      closure(array $params): PdfBuilder.
 */
class DocumentRegistry
{
    /** @return array<string, array{media: string, permission: ?string, rules: array, title: Closure, build: Closure}> */
    public function all(): array
    {
        return array_merge([
            'inventory-report' => [
                'media' => 'report', 'permission' => 'view-reports', 'rules' => [],
                'title' => fn () => 'Inventory Report',
                'build' => fn () => app(InventoryReportController::class)->pdfBuilder(),
            ],
            'outstanding-orders' => [
                'media' => 'report', 'permission' => 'view-reports', 'rules' => [],
                'title' => fn () => 'Outstanding Orders Report',
                'build' => fn () => app(OutstandingOrdersReportController::class)->pdfBuilder(),
            ],
            'unfulfilled-requests' => [
                'media' => 'report', 'permission' => 'view-reports',
                'rules' => UnfulfilledRequestsReportController::FILTER_RULES,
                'title' => fn () => 'Unfulfilled Requests Report',
                'build' => fn (array $p) => app(UnfulfilledRequestsReportController::class)->pdfBuilder(array_filter($p)),
            ],
            'inventory-flow' => [
                'media' => 'report', 'permission' => 'view-reports',
                'rules' => FlowReportController::FILTER_RULES,
                'title' => fn () => 'Inventory Flow Report',
                'build' => fn (array $p) => app(FlowReportController::class)->pdfBuilder(array_filter($p)),
            ],
            'partner-report' => [
                'media' => 'report', 'permission' => 'view-reports',
                'rules' => PartnerReportController::FILTER_RULES,
                'title' => fn () => 'Partner Report',
                'build' => fn (array $p) => app(PartnerReportController::class)->pdfBuilder(array_filter($p)),
            ],
            'donor-report' => [
                'media' => 'report', 'permission' => 'view-reports',
                'rules' => DonorReportController::FILTER_RULES,
                'title' => fn () => 'Donor Report',
                'build' => fn (array $p) => app(DonorReportController::class)->pdfBuilder(array_filter($p)),
            ],
            'mailing-labels' => [
                'media' => 'report', 'permission' => 'manage-people',
                'rules' => MailingLabelController::PRINT_RULES,
                'title' => fn (array $p) => 'Mailing labels ('.(MailingLabelController::AUDIENCES[$p['audience']] ?? $p['audience']).')',
                'build' => fn (array $p) => app(MailingLabelController::class)->labelsBuilder($p),
            ],
            'sitrep' => [
                'media' => 'report', 'permission' => 'view-sitrep', 'rules' => [],
                'title' => fn () => 'Situation Report',
                'build' => fn () => app(SitrepController::class)->pdfBuilder(app(WarehouseMetrics::class)),
            ],
            'order-form' => [
                'media' => 'report', 'permission' => 'manage-orders', 'rules' => [],
                'title' => fn () => 'Offline Order Form',
                'build' => fn () => app(OrderController::class)->orderFormBuilder(),
            ],
            'pick-sheets' => [
                'media' => 'report', 'permission' => 'manage-orders',
                'rules' => ['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:orderdonations,id'],
                'title' => fn (array $p) => 'Pick sheets ('.count($p['ids']).' order'.(count($p['ids']) === 1 ? '' : 's').')',
                'build' => fn (array $p) => app(OrderFillingController::class)->pickSheetsBuilder(array_map('intval', $p['ids'])),
            ],
            'bol' => [
                'media' => 'report', 'permission' => 'manage-orders',
                'rules' => ['id' => 'required|integer'],
                'title' => fn (array $p) => 'BOL for order #'.$p['id'],
                'build' => fn (array $p) => app(OrderController::class)->bolBuilder((int) $p['id']),
            ],
            'shipping-label' => [
                'media' => 'label_large', 'permission' => 'manage-orders',
                'rules' => ['id' => 'required|integer'],
                'title' => fn (array $p) => 'Shipping label for order #'.$p['id'],
                'build' => fn (array $p) => app(OrderController::class)->shippingLabelBuilder((int) $p['id']),
            ],
            'pallet-label' => [
                'media' => LabelSetting::palletMedia(), 'permission' => 'manage-pallets',
                'rules' => ['id' => 'required|integer'],
                'title' => fn (array $p) => 'Pallet label #'.$p['id'],
                'build' => fn (array $p) => app(PalletReportController::class)->singleLabelBuilder((int) $p['id']),
            ],
            'donation-pallet-labels' => [
                'media' => LabelSetting::palletMedia(), 'permission' => 'manage-receiving',
                'rules' => ['id' => 'required|integer'],
                'title' => fn (array $p) => 'Pallet labels for donation #'.$p['id'],
                'build' => fn (array $p) => app(PalletReportController::class)->donationLabelsBuilder((int) $p['id']),
            ],
            'inventory-labels' => [
                'media' => LabelSetting::inventoryMedia(), 'permission' => 'manage-pallets',
                'rules' => ['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer'],
                'title' => fn (array $p) => 'Inventory labels ('.count($p['ids']).')',
                'build' => fn (array $p) => app(InventoryLabelController::class)->builder(array_map('intval', $p['ids'])),
            ],
            'preprint-pallet-labels' => [
                'media' => LabelSetting::palletMedia(), 'permission' => 'manage-receiving',
                'rules' => ['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer'],
                'title' => fn (array $p) => 'Pre-printed pallet labels ('.count($p['ids']).')',
                'build' => fn (array $p) => app(PalletReportController::class)->preprintLabelsBuilder(array_map('intval', $p['ids'])),
            ],
        ], $this->helpGuides(), $this->training());
    }

    /** Training Mode: report cards, certificates, the setup packet + labels, and the team activity sheets. */
    private function training(): array
    {
        $documents = [
            'training-report-cards' => [
                'media' => 'report', 'permission' => 'manage-training', 'rules' => TrainingReportController::FILTER_RULES,
                'title' => fn () => 'Training report cards',
                'build' => fn (array $p) => app(TrainingReportController::class)->pdfBuilder($p),
            ],
            'training-certificates' => [
                'media' => 'report', 'permission' => 'manage-training', 'rules' => TrainingReportController::FILTER_RULES,
                'title' => fn () => 'Training certificates',
                'build' => fn (array $p) => app(TrainingReportController::class)->certificatesBuilder($p),
            ],
            'training-setup-packet' => [
                'media' => 'report', 'permission' => 'manage-training', 'rules' => [],
                'title' => fn () => 'Training setup packet',
                'build' => fn () => app(TrainingAdminController::class)->setupPacketBuilder(),
            ],
            'training-pallet-labels' => [
                'media' => LabelSetting::palletMedia(), 'permission' => 'manage-training', 'rules' => [],
                'title' => fn () => 'Training pallet labels',
                'build' => fn () => app(PalletReportController::class)->trainingLabelsBuilder(),
            ],
            'training-spot-labels' => [
                'media' => LabelSetting::palletMedia(), 'permission' => 'manage-training', 'rules' => [],
                'title' => fn () => 'Training table spot labels',
                'build' => fn () => app(TrainingAdminController::class)->spotLabelsBuilder(),
            ],
            'training-signup-poster' => [
                'media' => 'report', 'permission' => 'manage-training', 'rules' => [],
                'title' => fn () => 'Training sign-up poster',
                'build' => fn () => app(TrainingAdminController::class)->signupPosterBuilder(),
            ],
            'training-all-teams' => [
                'media' => 'report', 'permission' => null, 'rules' => [],
                'title' => fn () => 'Training team sheets (all teams)',
                'build' => fn () => app(TrainingAdminController::class)->teamSheetsBuilder(),
            ],
        ];
        foreach (TrainingMode::TEAMS as $team) {
            $documents['training-team-'.$team] = [
                'media' => 'report', 'permission' => null, 'rules' => [],
                'title' => fn () => "Training Team $team sheet",
                'build' => fn () => app(TrainingAdminController::class)->teamSheetsBuilder($team),
            ];
        }

        return $documents;
    }

    /** One `help-<slug>` document per guide in HelpReportController::GUIDES, plus `help-all` (every guide in one PDF). */
    private function helpGuides(): array
    {
        $documents = [];
        foreach (HelpReportController::GUIDES as $slug => $title) {
            $documents['help-'.$slug] = [
                'media' => 'report', 'permission' => null, 'rules' => [],
                'title' => fn () => $title,
                'build' => fn () => app(HelpReportController::class)->builder($slug),
            ];
        }
        $documents['help-all'] = [
            'media' => 'report', 'permission' => null, 'rules' => [],
            'title' => fn () => 'All Help Guides',
            'build' => fn () => app(HelpReportController::class)->allBuilder(),
        ];

        return $documents;
    }

    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function render(array $definition, array $params): PdfBuilder
    {
        return ($definition['build'])($params);
    }
}
