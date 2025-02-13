<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Pallet;
use Spatie\LaravelPdf\Facades\Pdf;

class PalletReportController extends Controller
{
    /**
     * Generate a PDF report for a given pallet.
     */
    public function generateReport($id)
    {
        $pallet = Pallet::findOrFail($id);
        return Pdf::view('reports.pallet', [
            'pallet_id' => $pallet->id,
            'pallet_id_str' => 'P'.str_pad(strval($pallet->id), 8, '0', STR_PAD_LEFT),
            'pallet_shortnum' => substr(str_pad(strval($pallet->id), 8, '0', STR_PAD_LEFT),6,2),
            'date_created' => $pallet->created_at->format('F d, Y'),
        ])->paperSize(4.0, 6.5, 'in')
          ->name('pallet-label-'.$pallet->id.'.pdf');
    }
}
