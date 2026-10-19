<?php

// Only the keys the app overrides; spatie/laravel-pdf merges the rest (including
// the weasyprint binary path, LARAVEL_PDF_WEASYPRINT_BINARY) from its own config.
return [

    /*
     * Every PDF in the app (labels, reports, order forms, guides) renders through
     * WeasyPrint, which has real CSS Paged Media support and needs no headless
     * browser. The package's own default is browsershot, which is not installed.
     */
    'driver' => env('LARAVEL_PDF_DRIVER', 'weasyprint'),

];
