<?php

// The situation report renders through the weasyprint driver (US Letter).

use App\Http\Controllers\SitrepController;
use App\Models\County;
use App\Models\Person;
use App\Models\Transaction;
use App\Services\WarehouseMetrics;

test('the sitrep renders as one weasyprint letter page with its sections and county rows', function () {
    foreach (['Thurston', 'Pierce', 'King'] as $name) {
        $county = County::create(['county' => $name, 'state' => 'WA']);
        $person = Person::create(['first_name' => 'A', 'last_name' => $name]);
        $person->county_id = $county->id;
        $person->save();
        Transaction::create([
            'type' => 'order', 'person_id' => $person->id,
            'status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER),
            'order_date' => now()->toDateString(),
        ]);
    }

    $path = tempnam(sys_get_temp_dir(), 'sit').'.pdf';
    app(SitrepController::class)->pdfBuilder(app(WarehouseMetrics::class))->save($path);

    $info = pdfInfo($path);
    expect($info)->toMatch('/Pages:\s+1\b/')
        ->and($info)->toContain('WeasyPrint')
        ->and($info)->toMatch('/Page size:\s+612(\.\d+)? x 792(\.\d+)? pts/');

    $text = pdfText($path);
    foreach (['Situation Report', 'Movement Summary', 'Current Pipeline', 'Orders by County', 'Orders by Zip', 'Stock on Hand', 'Thurston', 'Pierce', 'King'] as $expected) {
        expect($text)->toContain($expected);
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
// // Experiment: can the situation report render under weasyprint instead of
// // browsershot/Chromium? Renders the same builder through both drivers and
// // compares page count and extracted text. Set LABEL_RENDER_DIR to keep the
// // PDFs + PNGs for eyeballing.
//
// use App\Http\Controllers\SitrepController;
// use App\Models\County;
// use App\Models\Person;
// use App\Models\Transaction;
// use App\Services\WarehouseMetrics;
//
// function renderSitrep(string $driver): string
// {
//     $path = tempnam(sys_get_temp_dir(), 'sit').'.pdf';
//     app(SitrepController::class)->pdfBuilder(app(WarehouseMetrics::class))->driver($driver)->save($path);
//
//     return $path;
// }
//
// test('the sitrep renders under weasyprint with the same pages and text as chromium', function () {
//     foreach (['Thurston', 'Pierce', 'King'] as $name) {
//         $county = County::create(['county' => $name, 'state' => 'WA']);
//         $person = Person::create(['first_name' => 'A', 'last_name' => $name]);
//         $person->county_id = $county->id;
//         $person->save();
//         Transaction::create([
//             'type' => 'order', 'person_id' => $person->id,
//             'status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER),
//             'order_date' => now()->toDateString(),
//         ]);
//     }
//
//     $weasy = renderSitrep('weasyprint');
//     $chrome = renderSitrep('browsershot');
//
//     if ($dir = getenv('LABEL_RENDER_DIR')) {
//         @mkdir($dir, 0777, true);
//         foreach (['sitrep-weasy' => $weasy, 'sitrep-chrome' => $chrome] as $name => $pdf) {
//             copy($pdf, "$dir/$name.pdf");
//             shell_exec('pdftoppm -png -r 80 '.escapeshellarg($pdf).' '.escapeshellarg("$dir/$name"));
//         }
//     }
//
//     foreach ([$weasy, $chrome] as $pdf) {
//         $info = shell_exec('pdfinfo '.escapeshellarg($pdf));
//         expect($info)->toMatch('/Pages:\s+1\b/')
//             ->and($info)->toMatch('/Page size:\s+612(\.\d+)? x 792(\.\d+)? pts/');
//     }
//
//     $words = fn (string $pdf) => preg_split('/\s+/', trim(shell_exec('pdftotext -layout '.escapeshellarg($pdf).' -')));
//     // The generated-at timestamp differs by seconds only in theory; both ran within the same minute.
//     expect($words($weasy))->toEqualCanonicalizing($words($chrome));
// });
