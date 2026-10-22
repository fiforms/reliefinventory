<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\LabelSetting;
use App\Models\PalletStockAssignment;
use App\Support\InventoryLabelCode;
use App\Support\LabelBarcode;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Inventory labels: one per pallet_stock_assignments row (an item and quantity put on a pallet), numbered
 * "ACS:<family>-<variant>:<row id>" (InventoryLabelCode) and showing the ACS item number and description, the quantity,
 * the earliest expiration of that quantity and the pallet. Printed on LabelSetting::inventoryMedia() (the small label by
 * default). What a label says is what was put on the pallet then — picks and corrections made since aren't reflected.
 *
 * The Code 128 is drawn in whole printer dots (see barcode()): the code is ~18 characters, 3.4 in at a scannable 3 dots per
 * module, so on the 2.312 in label it is rotated to run along the 4 in length.
 */
class InventoryLabelController extends Controller
{
    private const DPI = LabelBarcode::DPI; // dots per inch the label printers here use; bars are sized to land on whole dots at this resolution

    private const QUIET_MODULES = 10; // blank modules either side of a barcode

    private const MARGIN_DOTS = 16;

    private const BAR_HEIGHT_IN = 0.42; // compact; the full-size label uses 0.5 (see barcode())

    /** Browser download of the labels for the assignment ids in ?ids=1,2,3. */
    public function download(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))->map(fn ($id) => (int) trim($id))->filter()->unique()->values()->all();

        return $this->builder($ids);
    }

    public function builder(array $ids): PdfBuilder
    {
        $assignments = PalletStockAssignment::with(['item.itemtype', 'pallet'])
            ->whereIn('id', $ids)->where('qty', '>', 0)->get()->sortBy(fn ($a) => array_search($a->id, $ids))->values();

        if ($assignments->isEmpty()) {
            abort(404, 'No matching inventory labels found.');
        }

        $media = LabelSetting::inventoryMedia();
        $size = config("printing.media.{$media}");
        $compact = LabelSetting::isCompact($media);

        return Pdf::view('reports.inventory-labels', [
            'labels' => $assignments->map(fn (PalletStockAssignment $a) => $this->labelData($a, $size, $compact))->all(),
            'paper' => ['width' => $size['width'], 'height' => $size['height']],
            'compact' => $compact,
        ])->driver('weasyprint')->paperSize($size['width'], $size['height'], 'in')
            ->name('inventory-labels.pdf');
    }

    private function labelData(PalletStockAssignment $assignment, array $size, bool $compact): array
    {
        $type = $assignment->item?->itemtype;
        $code = InventoryLabelCode::make($assignment);

        return [
            'code' => $code,
            'item_number' => $type?->display_number ?: '—',
            'description' => $type?->name ?: ($assignment->item?->description ?? 'Item #'.$assignment->item_id),
            'qty' => $assignment->qty,
            'expires_on' => $assignment->expires_on?->format('M j, Y'),
            'pallet' => $assignment->pallet?->tag,
            // Along the label's long edge when rotated, across its width otherwise.
            'barcode' => $this->barcode($code, (int) round(($compact ? $size['height'] : $size['width']) * self::DPI) - 2 * self::MARGIN_DOTS, $compact),
        ];
    }

    /**
     * The barcode laid out in whole dots (see LabelBarcode): the widest module (2–6 dots) that fits `$availableDots`, so a
     * 1-module bar prints as exactly that many dots. The label view places the bars' origin on a whole dot too.
     *
     * @return array{html: string, length_dots: int, module_dots: int, px_per_dot: float, bar_height_px: int}
     */
    private function barcode(string $code, int $availableDots, bool $compact): array
    {
        $pxPerDot = LabelBarcode::pxPerDot();
        $modules = LabelBarcode::modules($code);

        $moduleDots = 2;
        for ($d = 6; $d >= 2; $d--) {
            if (($modules + 2 * self::QUIET_MODULES) * $d <= $availableDots) {
                $moduleDots = $d;
                break;
            }
        }

        $barHeightPx = (int) round(($compact ? self::BAR_HEIGHT_IN : 0.5) * self::DPI * $pxPerDot);

        return [
            'html' => LabelBarcode::html($code, $moduleDots, $barHeightPx),
            'length_dots' => ($modules + 2 * self::QUIET_MODULES) * $moduleDots,
            'module_dots' => $moduleDots,
            'px_per_dot' => $pxPerDot,
            'bar_height_px' => $barHeightPx,
        ];
    }
}
