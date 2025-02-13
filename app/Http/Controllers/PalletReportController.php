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
            'date_created' => $pallet->created_at->format('F d, Y'),
        ])->format('letter')
          ->name('invoice.pdf');
    }
}
