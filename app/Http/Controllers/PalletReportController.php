<?php

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Models\Transaction;
use App\Support\PalletKind;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

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
        return $this->singleLabelBuilder($id);
    }

    /**
     * Large-label paper size, from the same media definition printers are typed with.
     * Rendered with weasyprint; the views set a matching zero-margin CSS page size,
     * which weasyprint needs to avoid stacking its default page margins on the label.
     */
    private function labelPaper(PdfBuilder $pdf): PdfBuilder
    {
        $media = config('printing.media.label_large');

        return $pdf->driver('weasyprint')->paperSize($media['width'], $media['height'], 'in');
    }

    // The *Builder methods return the unrendered PDF so the same code feeds
    // both the browser download routes above/below and the print queue
    // (see App\Services\Printing\DocumentRegistry).
    public function singleLabelBuilder($id): PdfBuilder
    {
        $pallet = Pallet::with('contentItem')->findOrFail($id);

        return $this->labelPaper(Pdf::view('reports.pallet', ['label' => $this->labelData($pallet)]))
            ->name('pallet-label-'.$pallet->tag.'.pdf');
    }

    /**
     * All of a donation's pallet labels in one PDF (one label per page) —
     * a single print job instead of one browser tab per pallet.
     */
    public function generateDonationLabels($id)
    {
        return $this->donationLabelsBuilder($id);
    }

    public function donationLabelsBuilder($id): PdfBuilder
    {
        $donation = Transaction::where('type', 'donation')->with('pallets.contentItem')->findOrFail($id);

        if ($donation->pallets->isEmpty()) {
            abort(404, 'No pallets have been created for this donation.');
        }

        return $this->labelPaper(Pdf::view('reports.pallet-batch', [
            'labels' => $donation->pallets->map(fn (Pallet $pallet) => $this->labelData($pallet))->all(),
        ]))->name('pallet-labels-donation-'.$donation->id.'.pdf');
    }

    /**
     * Batch print for an arbitrary set of just-created, not-yet-assigned
     * pallet ids — the Pre-print Labels action in Receiving (see
     * ReceivingController::preprintLabels). Scoped to kind R and
     * orderdonation_id null so this can't be used to reprint/leak labels
     * that already belong to a real donation.
     */
    public function generatePreprintLabels(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->all();

        return $this->preprintLabelsBuilder($ids);
    }

    public function preprintLabelsBuilder(array $ids): PdfBuilder
    {
        $pallets = Pallet::whereIn('id', $ids)->where('kind', 'R')->whereNull('orderdonation_id')->get();

        if ($pallets->isEmpty()) {
            abort(404, 'No matching pre-printed labels found.');
        }

        return $this->labelPaper(Pdf::view('reports.pallet-batch', [
            'labels' => $pallets->map(fn (Pallet $pallet) => $this->labelData($pallet))->all(),
        ]))->name('pallet-labels-preprint.pdf');
    }
}
