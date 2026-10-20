<?php

// Every Help guide renders through the weasyprint driver (US Letter, multi-page prose),
// with its margins on @page so they hold past the first page.

use App\Http\Controllers\HelpReportController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('a help guide renders as weasyprint letter pages with its content', function (string $guide, array $expected) {
    $path = tempnam(sys_get_temp_dir(), 'help').'.pdf';
    app(HelpReportController::class)->builder($guide)->save($path);

    $info = pdfInfo($path);
    expect($info)->toContain('WeasyPrint')
        ->and($info)->toMatch('/Pages:\s+[2-9]\b/')
        ->and($info)->toMatch('/Page size:\s+612(\.\d+)? x 792(\.\d+)? pts/');

    $text = pdfText($path);
    foreach ($expected as $phrase) {
        expect($text)->toContain($phrase);
    }
})->with([
    'receiving' => ['receiving', ['Receiving Guide', 'Welcoming the driver', 'Step by step', 'ready to go to the staging area']],
    'sorting' => ['sorting', ['Donation Sorting Guide', 'Working a pallet', 'Pallet Empty', 'Complete Session']],
])->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('an unknown help guide is a 404', function () {
    app(HelpReportController::class)->builder('nonexistent');
})->throws(NotFoundHttpException::class);

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
// // Experiment: can the Receiving Guide PDF render under weasyprint instead of
// // browsershot/Chromium? Set LABEL_RENDER_DIR to keep the PDFs + PNGs.
//
// use App\Http\Controllers\HelpReportController;
//
// function renderGuide(string $driver): string
// {
//     $path = tempnam(sys_get_temp_dir(), 'help').'.pdf';
//     app(HelpReportController::class)->builder('receiving')->driver($driver)->save($path);
//
//     return $path;
// }
//
// test('the receiving guide renders under weasyprint with the same text as chromium', function () {
//     $weasy = renderGuide('weasyprint');
//     $chrome = renderGuide('browsershot');
//
//     if ($dir = getenv('LABEL_RENDER_DIR')) {
//         @mkdir($dir, 0777, true);
//         foreach (['help-weasy' => $weasy, 'help-chrome' => $chrome] as $name => $pdf) {
//             copy($pdf, "$dir/$name.pdf");
//             shell_exec('pdftoppm -png -r 60 '.escapeshellarg($pdf).' '.escapeshellarg("$dir/$name"));
//         }
//     }
//
//     $pages = fn (string $pdf) => (int) (preg_match('/Pages:\s+(\d+)/', shell_exec('pdfinfo '.escapeshellarg($pdf)), $m) ? $m[1] : 0);
//     // Bullet glyphs and non-breaking spaces extract differently between engines; the words don't.
//     $words = fn (string $pdf) => preg_split('/\s+/u', trim(str_replace(['•', "\u{a0}"], ' ', shell_exec('pdftotext -layout '.escapeshellarg($pdf).' -'))));
//
//     expect($words($weasy))->toEqualCanonicalizing($words($chrome))
//         ->and($pages($weasy))->toBe($pages($chrome));
// });
