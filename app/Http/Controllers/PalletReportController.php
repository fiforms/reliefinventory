<?php

namespace App\Http\Controllers;

use App\Models\LabelSetting;
use App\Models\Pallet;
use App\Models\TrainingSetting;
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
            // Where the pallet was headed when printed (set by Fill Pallet); can go stale if moved later.
            'location' => $pallet->location?->code,
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
     * Label paper size, from the same media definition printers are typed with — the media chosen in
     * LabelSetting (4 x 6.5 in by default). Rendered with weasyprint; the views set a matching
     * zero-margin CSS page size (from the `paper`/`compact` view data below), which weasyprint needs
     * to avoid stacking its default page margins on the label.
     */
    private function labelPaper(PdfBuilder $pdf): PdfBuilder
    {
        $media = config('printing.media.'.LabelSetting::palletMedia());

        return $pdf->driver('weasyprint')->paperSize($media['width'], $media['height'], 'in');
    }

    /** View data every pallet label view needs to lay itself out for the current label media. */
    private function layout(): array
    {
        $media = config('printing.media.'.LabelSetting::palletMedia());

        return ['paper' => ['width' => $media['width'], 'height' => $media['height']], 'compact' => LabelSetting::palletLabelIsCompact()];
    }

    // The *Builder methods return the unrendered PDF so the same code feeds
    // both the browser download routes above/below and the print queue
    // (see App\Services\Printing\DocumentRegistry).
    public function singleLabelBuilder($id): PdfBuilder
    {
        $pallet = Pallet::with(['contentItem', 'location'])->findOrFail($id);

        return $this->labelPaper(Pdf::view('reports.pallet', ['label' => $this->labelData($pallet)] + $this->layout()))
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
        $donation = Transaction::where('type', 'donation')->with(['pallets.contentItem', 'pallets.location'])->findOrFail($id);

        if ($donation->pallets->isEmpty()) {
            abort(404, 'No pallets have been created for this donation.');
        }

        return $this->labelPaper(Pdf::view('reports.pallet-batch', [
            'labels' => $donation->pallets->map(fn (Pallet $pallet) => $this->labelData($pallet))->all(),
        ] + $this->layout()))->name('pallet-labels-donation-'.$donation->id.'.pdf');
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
        ] + $this->layout()))->name('pallet-labels-preprint.pdf');
    }

    /**
     * Labels for the pallets the last Prepare Training made (Training Mode's setup packet): the sealed W pallets on
     * the table spots. Only those ids, so it can't print anyone else's labels.
     */
    public function trainingLabelsBuilder(): PdfBuilder
    {
        $summary = TrainingSetting::current()->prepared_summary ?? [];
        $ids = array_column($summary['warehouse_pallets'] ?? [], 'id');
        $pallets = Pallet::with(['contentItem', 'location'])->whereIn('id', $ids)->orderBy('kind')->orderBy('id')->get();

        if ($pallets->isEmpty()) {
            abort(404, 'Prepare Training has not made any pallets yet.');
        }

        return $this->labelPaper(Pdf::view('reports.pallet-batch', [
            'labels' => $pallets->map(fn (Pallet $pallet) => $this->labelData($pallet))->all(),
        ] + $this->layout()))->name('pallet-labels-training.pdf');
    }
}
