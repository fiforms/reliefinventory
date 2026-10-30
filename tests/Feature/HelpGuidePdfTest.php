<?php

// Every Help guide renders through the weasyprint driver (US Letter, multi-page prose),
// with its margins on @page so they hold past the first page.

use App\Http\Controllers\HelpReportController;
use App\Models\MenuItem;
use App\Support\HelpGuide;
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
    'receiving' => ['receiving', ['Receiving Guide', 'Log what comes off the truck', 'Welcoming the driver', 'Step 1', 'Print Labels']],
    'sorting' => ['sorting', ['Donation Sorting Guide', 'Working a pallet', 'Pallet Empty', 'Complete Session']],
])->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('every registered guide has a Markdown source with an intro and headed sections', function (string $guide) {
    expect(file_exists(HelpGuide::path($guide)))->toBeTrue();
    $source = HelpGuide::load($guide);
    expect($source->intro)->not->toBe('')
        ->and($source->sections)->not->toBeEmpty()
        ->and(collect($source->sections)->filter(fn ($html) => str_starts_with($html, '<h2>')))->not->toBeEmpty();
})->with(array_keys(HelpReportController::GUIDES));

test('a guide source can carry no raw HTML or unsafe links', function () {
    $path = HelpGuide::path('probe', 'test');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "Intro.\n\n## Heading\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))\n");
    try {
        $html = implode('', HelpGuide::load('probe', 'test')->sections);
    } finally {
        unlink($path);
        @rmdir(dirname($path));
    }
    expect($html)->not->toContain('<script')->and($html)->not->toContain('javascript:');
});

test('every guide is linked from at least one page with a HelpLink', function (string $guide) {
    $linked = false;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js/Pages')));
    foreach ($files as $file) {
        if ($file->getExtension() === 'vue' && ! str_contains($file->getPathname(), '/Pages/Help/')
            && str_contains(file_get_contents($file->getPathname()), '<HelpLink guide="'.$guide.'"')) {
            $linked = true;
            break;
        }
    }
    expect($linked)->toBeTrue();
})->with(array_keys(HelpReportController::GUIDES));

test('a guide page renders from its Markdown source for a signed-in user', function () {
    $user = userWithPermissions('general-access', 'manage-receiving');
    $this->actingAs($user)->get('/help/receiving')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Help/Guide')
        ->where('guide', 'receiving')
        ->where('title', 'Receiving')
        ->where('intro', fn ($intro) => str_contains($intro, 'Log what comes off the truck'))
        ->where('sections', fn ($sections) => str_contains(implode('', $sections->all()), '<h2>Welcoming the driver &amp; safety</h2>')));
    $this->actingAs($user)->get('/help/nonexistent')->assertNotFound();
});

test('the combined PDF holds every guide, each starting on its own page', function () {
    $path = tempnam(sys_get_temp_dir(), 'help').'.pdf';
    app(HelpReportController::class)->allBuilder()->save($path);

    $text = pdfText($path);
    foreach (HelpReportController::GUIDES as $title) {
        expect(substr_count($text, $title))->toBeGreaterThanOrEqual(2); // contents + its own heading
    }
    expect($text)->toContain('Welcoming the driver')->and($text)->toContain('Complete Session');
    preg_match('/Pages:\s+(\d+)/', pdfInfo($path), $m);
    expect((int) $m[1])->toBeGreaterThan(count(HelpReportController::GUIDES));
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('the help list shows every guide, is on the main menu, and the combined PDF downloads', function () {
    $user = userWithPermissions('general-access');
    $this->actingAs($user)->get('/help')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Help/Index')
        ->has('guides', count(HelpReportController::GUIDES))
        ->where('guides.0.slug', array_key_first(HelpReportController::GUIDES)));
    $this->actingAs($user)->get('/help/shipping')->assertInertia(fn ($page) => $page
        ->where('breadcrumb.0.href', '/help')
        ->where('breadcrumb.1.title', 'Shipping Guide'));
    expect(MenuItem::where('link_url', '/help')->whereNull('permission_key')->exists())->toBeTrue();
    $this->actingAs($user)->get('/report/help/all')->assertOk();
    auth()->logout();
    $this->get('/help')->assertRedirect('/login');
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

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
