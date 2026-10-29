<?php

// Pallet labels render through the weasyprint driver (4 x 6.5 in, one label per page).

use App\Http\Controllers\PalletReportController;
use App\Models\LabelSetting;
use App\Models\Location;
use App\Models\Pallet;
use App\Models\UseModel;
use App\Models\Warehouse;

function newPallet(): Pallet
{
    return Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
}

test('preprint labels render one 4 x 6.5 in weasyprint page per pallet', function () {
    $pallets = collect(range(1, 3))->map(fn () => newPallet());

    $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
    app(PalletReportController::class)->preprintLabelsBuilder($pallets->pluck('id')->all())->save($path);

    $info = pdfInfo($path);
    expect($info)->toMatch('/Pages:\s+3\b/')
        ->and($info)->toContain('WeasyPrint')
        ->and($info)->toMatch('/Page size:\s+288(\.\d+)? x 468(\.\d+)? pts/');

    $text = pdfText($path);
    foreach ($pallets as $pallet) {
        expect($text)->toContain($pallet->tag);
    }
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('a single pallet label renders as one 4 x 6.5 in weasyprint page', function () {
    $pallet = newPallet();

    $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
    app(PalletReportController::class)->singleLabelBuilder($pallet->id)->save($path);

    $info = pdfInfo($path);
    expect($info)->toMatch('/Pages:\s+1\b/')
        ->and($info)->toContain('WeasyPrint')
        ->and($info)->toMatch('/Page size:\s+288(\.\d+)? x 468(\.\d+)? pts/')
        ->and(pdfText($path))->toContain($pallet->tag);
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('a pallet label shows its location when it has one, on both label sizes', function () {
    $wh = Warehouse::first() ?? Warehouse::create(['name' => 'WH']);
    $use = UseModel::first() ?? UseModel::create(['use' => 'Storage']);
    $location = Location::forceCreate(['warehouse_id' => $wh->id, 'use_id' => $use->id, 'code' => 'A-12-3', 'status' => 'active']);
    $placed = newPallet();
    $placed->update(['location_id' => $location->id]);
    $unplaced = newPallet();

    foreach (['label_large', 'label_small'] as $media) {
        LabelSetting::query()->updateOrCreate(['id' => 1], ['pallet_label_media' => $media]);

        $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
        app(PalletReportController::class)->singleLabelBuilder($placed->id)->save($path);
        expect(pdfInfo($path))->toMatch('/Pages:\s+1\b/')
            ->and(pdfText($path))->toContain('Location: A-12-3');

        app(PalletReportController::class)->singleLabelBuilder($unplaced->id)->save($path);
        expect(pdfText($path))->not->toContain('Location:');
    }
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

/*
 * Chrome comparison (removed 2026-10-19, kept for reference).
 *
 * While migrating every PDF from headless Chrome to WeasyPrint, this test rendered
 * the same builder through both engines and compared page count, page size and
 * extracted text, and (with LABEL_RENDER_DIR set) dumped PDFs + PNGs to eyeball.
 * To bring it back for a future engine change: `composer require spatie/browsershot`
 * and `npm install puppeteer`, provision Chrome (see the git history of CLAUDE.md's
 * "PDF / label generation" section), then restore the code below.
 */

//
// // Experiment: can the pre-print pallet labels render under weasyprint instead of
// // browsershot/Chromium? Renders the same builder through both drivers and
// // compares page count, page size and extracted text. Set LABEL_RENDER_DIR to
// // keep the PDFs + PNGs for eyeballing (barcodes especially).
//
// use App\Http\Controllers\PalletReportController;
// use App\Models\Pallet;
//
// function renderLabels(string $driver, array $ids): string
// {
//     $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
//     app(PalletReportController::class)->preprintLabelsBuilder($ids)->driver($driver)->save($path);
//
//     return $path;
// }
//
// test('preprint labels render under weasyprint with the same pages and text as chromium', function () {
//     $ids = collect(range(1, 3))->map(fn () => Pallet::create([
//         'kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString(),
//     ])->id)->all();
//
//     $weasy = renderLabels('weasyprint', $ids);
//     $chrome = renderLabels('browsershot', $ids);
//
//     if ($dir = getenv('LABEL_RENDER_DIR')) {
//         @mkdir($dir, 0777, true);
//         foreach (['weasy' => $weasy, 'chrome' => $chrome] as $name => $pdf) {
//             copy($pdf, "$dir/$name.pdf");
//             shell_exec('pdftoppm -png -r 100 '.escapeshellarg($pdf).' '.escapeshellarg("$dir/$name"));
//         }
//     }
//
//     foreach ([$weasy, $chrome] as $pdf) {
//         $info = shell_exec('pdfinfo '.escapeshellarg($pdf));
//         expect($info)->toMatch('/Pages:\s+3\b/');
//         // 4 x 6.5 in = 288 x 468 pt
//         expect($info)->toMatch('/Page size:\s+288(\.\d+)? x 468(\.\d+)? pts/');
//     }
//
//     $words = fn (string $pdf) => preg_split('/\s+/', trim(shell_exec('pdftotext -layout '.escapeshellarg($pdf).' -')));
//     expect($words($weasy))->toEqualCanonicalizing($words($chrome));
//
// });
//
// test('a single pallet label renders as one 4 x 6.5 in weasyprint page', function () {
//     $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
//
//     $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
//     app(PalletReportController::class)->singleLabelBuilder($pallet->id)->save($path);
//
//     $info = shell_exec('pdfinfo '.escapeshellarg($path));
//     expect($info)->toMatch('/Pages:\s+1\b/')
//         ->and($info)->toContain('WeasyPrint')
//         ->and($info)->toMatch('/Page size:\s+288(\.\d+)? x 468(\.\d+)? pts/');
//
//     if ($dir = getenv('LABEL_RENDER_DIR')) {
//         @mkdir($dir, 0777, true);
//         shell_exec('pdftoppm -png -r 100 '.escapeshellarg($path).' '.escapeshellarg("$dir/single"));
//     }
// });
