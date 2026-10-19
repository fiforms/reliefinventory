<?php

// The Receiving Guide renders through the weasyprint driver (US Letter, multi-page prose).

use App\Http\Controllers\HelpReportController;

test('the receiving guide renders as weasyprint letter pages with its content', function () {
    $path = tempnam(sys_get_temp_dir(), 'help').'.pdf';
    app(HelpReportController::class)->receivingBuilder()->save($path);

    $info = pdfInfo($path);
    expect($info)->toContain('WeasyPrint')
        ->and($info)->toMatch('/Pages:\s+[2-9]\b/')
        ->and($info)->toMatch('/Page size:\s+612(\.\d+)? x 792(\.\d+)? pts/');

    $text = pdfText($path);
    foreach (['Receiving Guide', 'Welcoming the driver', 'Step by step', 'ready to go to the staging area'] as $expected) {
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
// // Experiment: can the Receiving Guide PDF render under weasyprint instead of
// // browsershot/Chromium? Set LABEL_RENDER_DIR to keep the PDFs + PNGs.
//
// use App\Http\Controllers\HelpReportController;
//
// function renderGuide(string $driver): string
// {
//     $path = tempnam(sys_get_temp_dir(), 'help').'.pdf';
//     app(HelpReportController::class)->receivingBuilder()->driver($driver)->save($path);
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
