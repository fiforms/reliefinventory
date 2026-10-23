<?php

// Every Help guide renders through the weasyprint driver (US Letter, multi-page prose),
// with its margins on @page so they hold past the first page.

use App\Http\Controllers\HelpReportController;
use App\Models\MenuItem;
use Illuminate\Support\Str;
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

test('every registered guide has a printable view and a Vue page', function (string $guide) {
    expect(view()->exists('reports.help.'.$guide))->toBeTrue()
        ->and(file_exists(resource_path('js/Pages/Help/'.Str::studly($guide).'.vue')))->toBeTrue();
})->with(array_keys(HelpReportController::GUIDES));

test('every page guide mapping points at a registered guide', function () {
    foreach (HelpReportController::PAGE_GUIDES as $path => $slug) {
        expect(HelpReportController::GUIDES)->toHaveKey($slug);
        expect(HelpReportController::guideForPath('/'.$path.'/'))->toBe($slug);
    }
    expect(HelpReportController::guideForPath('dashboard'))->toBeNull();
});

test('a guide page renders for a signed-in user and the page shares its guide slug', function () {
    $user = userWithPermissions('general-access', 'manage-receiving');
    $this->actingAs($user)->get('/help/order-entry')->assertOk();
    $this->actingAs($user)->get('/help/nonexistent')->assertNotFound();
    $this->actingAs($user)->get('/receiving')->assertInertia(fn ($page) => $page->where('helpGuide', 'receiving'));
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
