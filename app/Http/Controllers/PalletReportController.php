<?php

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Support\PalletKind;
use Spatie\LaravelPdf\Facades\Pdf;

class PalletReportController extends Controller
{
    /**
     * Generate a PDF report/label for a given pallet.
     */
    public function generateReport($id)
    {
        $pallet = Pallet::findOrFail($id);

        return Pdf::view('reports.pallet', [
            'pallet_id' => $pallet->id,
            'pallet_id_str' => $pallet->tag,
            'pallet_shortnum' => substr($pallet->tag, -2),
            'pallet_kind_label' => PalletKind::LABELS[$pallet->kind] ?? $pallet->kind,
            'date_created' => $pallet->created_at->format('F d, Y'),
        ])->paperSize(4.0, 6.5, 'in')
            ->name('pallet-label-'.$pallet->tag.'.pdf');
    }
}
