<?php

// Experiment: can the Receiving Guide PDF render under weasyprint instead of
// browsershot/Chromium? Set LABEL_RENDER_DIR to keep the PDFs + PNGs.

use App\Http\Controllers\HelpReportController;

function renderGuide(string $driver): string
{
    $path = tempnam(sys_get_temp_dir(), 'help').'.pdf';
    app(HelpReportController::class)->receivingBuilder()->driver($driver)->save($path);

    return $path;
}

test('the receiving guide renders under weasyprint with the same text as chromium', function () {
    $weasy = renderGuide('weasyprint');
    $chrome = renderGuide('browsershot');

    if ($dir = getenv('LABEL_RENDER_DIR')) {
        @mkdir($dir, 0777, true);
        foreach (['help-weasy' => $weasy, 'help-chrome' => $chrome] as $name => $pdf) {
            copy($pdf, "$dir/$name.pdf");
            shell_exec('pdftoppm -png -r 60 '.escapeshellarg($pdf).' '.escapeshellarg("$dir/$name"));
        }
    }

    $pages = fn (string $pdf) => (int) (preg_match('/Pages:\s+(\d+)/', shell_exec('pdfinfo '.escapeshellarg($pdf)), $m) ? $m[1] : 0);
    // Bullet glyphs and non-breaking spaces extract differently between engines; the words don't.
    $words = fn (string $pdf) => preg_split('/\s+/u', trim(str_replace(['•', "\u{a0}"], ' ', shell_exec('pdftotext -layout '.escapeshellarg($pdf).' -'))));

    expect($words($weasy))->toEqualCanonicalizing($words($chrome))
        ->and($pages($weasy))->toBe($pages($chrome));
});
