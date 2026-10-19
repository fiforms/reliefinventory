<?php

use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;

test('weasyprint is the default pdf driver, so a Pdf::view that forgets ->driver() still works', function () {
    expect(config('laravel-pdf.driver'))->toBe('weasyprint');

    // No explicit ->driver(): relies on the default alone.
    $path = tempnam(sys_get_temp_dir(), 'drv').'.pdf';
    Pdf::html('<p>default driver</p>')->save($path);

    expect(file_get_contents($path, false, null, 0, 5))->toBe('%PDF-');
});

test('headless Chrome is gone: browsershot is not installed', function () {
    expect(class_exists(Browsershot::class))->toBeFalse();
});
