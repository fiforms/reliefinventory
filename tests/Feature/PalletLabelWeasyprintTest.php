<?php

// Experiment: can the pre-print pallet labels render under weasyprint instead of
// browsershot/Chromium? Renders the same builder through both drivers and
// compares page count, page size and extracted text. Set LABEL_RENDER_DIR to
// keep the PDFs + PNGs for eyeballing (barcodes especially).

use App\Http\Controllers\PalletReportController;
use App\Models\Pallet;

function renderLabels(string $driver, array $ids): string
{
    $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
    app(PalletReportController::class)->preprintLabelsBuilder($ids)->driver($driver)->save($path);

    return $path;
}

test('preprint labels render under weasyprint with the same pages and text as chromium', function () {
    $ids = collect(range(1, 3))->map(fn () => Pallet::create([
        'kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString(),
    ])->id)->all();

    $weasy = renderLabels('weasyprint', $ids);
    $chrome = renderLabels('browsershot', $ids);

    if ($dir = getenv('LABEL_RENDER_DIR')) {
        @mkdir($dir, 0777, true);
        foreach (['weasy' => $weasy, 'chrome' => $chrome] as $name => $pdf) {
            copy($pdf, "$dir/$name.pdf");
            shell_exec('pdftoppm -png -r 100 '.escapeshellarg($pdf).' '.escapeshellarg("$dir/$name"));
        }
    }

    foreach ([$weasy, $chrome] as $pdf) {
        $info = shell_exec('pdfinfo '.escapeshellarg($pdf));
        expect($info)->toMatch('/Pages:\s+3\b/');
        // 4 x 6.5 in = 288 x 468 pt
        expect($info)->toMatch('/Page size:\s+288(\.\d+)? x 468(\.\d+)? pts/');
    }

    $words = fn (string $pdf) => preg_split('/\s+/', trim(shell_exec('pdftotext -layout '.escapeshellarg($pdf).' -')));
    expect($words($weasy))->toEqualCanonicalizing($words($chrome));

});

test('a single pallet label renders as one 4 x 6.5 in weasyprint page', function () {
    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);

    $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
    app(PalletReportController::class)->singleLabelBuilder($pallet->id)->save($path);

    $info = shell_exec('pdfinfo '.escapeshellarg($path));
    expect($info)->toMatch('/Pages:\s+1\b/')
        ->and($info)->toContain('WeasyPrint')
        ->and($info)->toMatch('/Page size:\s+288(\.\d+)? x 468(\.\d+)? pts/');

    if ($dir = getenv('LABEL_RENDER_DIR')) {
        @mkdir($dir, 0777, true);
        shell_exec('pdftoppm -png -r 100 '.escapeshellarg($path).' '.escapeshellarg("$dir/single"));
    }
});
