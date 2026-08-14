<?php

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Models\Transaction;
use App\Support\PalletKind;
use Spatie\LaravelPdf\Facades\Pdf;

class PalletReportController extends Controller
{
    private function labelData(Pallet $pallet): array
    {
        return [
            'pallet_id' => $pallet->id,
            'pallet_id_str' => $pallet->tag,
            'pallet_shortnum' => substr($pallet->tag, -2),
            'pallet_kind_label' => PalletKind::LABELS[$pallet->kind] ?? $pallet->kind,
            'contents' => $pallet->contentItem?->name
                ?: $pallet->contentItem?->description
                ?: $pallet->content_description,
            'date_created' => $pallet->created_at->format('F d, Y'),
        ];
    }

    /**
     * Generate a PDF report/label for a given pallet.
     */
    public function generateReport($id)
    {
        $pallet = Pallet::with('contentItem')->findOrFail($id);

        return Pdf::view('reports.pallet', ['label' => $this->labelData($pallet)])
            ->paperSize(4.0, 6.5, 'in')
            ->name('pallet-label-'.$pallet->tag.'.pdf');
    }

    /**
     * All of a donation's pallet labels in one PDF (one label per page) —
     * a single print job instead of one browser tab per pallet.
     */
    public function generateDonationLabels($id)
    {
        $donation = Transaction::where('type', 'donation')->with('pallets.contentItem')->findOrFail($id);

        if ($donation->pallets->isEmpty()) {
            abort(404, 'No pallets have been created for this donation.');
        }

        return Pdf::view('reports.pallet-batch', [
            'labels' => $donation->pallets->map(fn (Pallet $pallet) => $this->labelData($pallet))->all(),
        ])->paperSize(4.0, 6.5, 'in')
            ->name('pallet-labels-donation-'.$donation->id.'.pdf');
    }
}
