<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Signs (SignController, App\Support\SignLayout, App\Support\SignTemplates).

use App\Http\Controllers\SignController;
use App\Models\MenuItem;
use App\Models\PrintJob;
use App\Support\SignLayout;
use App\Support\SignTemplates;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

function signRectsOverlap(array $a, array $b): bool
{
    return $a['x'] < $b['x'] + $b['width'] && $b['x'] < $a['x'] + $a['width']
        && $a['y'] < $b['y'] + $b['height'] && $b['y'] < $a['y'] + $a['height'];
}

/** Every line inside the text box, the block inside it down the page, and no arrow on the text. */
function expectSignFits(array $sign): void
{
    $layout = SignLayout::make($sign);
    $box = $layout['box'];
    foreach ([[$layout['title'], 'bold'], [$layout['subtitle'], 'regular']] as [$part, $weight]) {
        foreach ($part['lines'] ?? [] as $line) {
            expect(SignLayout::width($line, $weight) * $part['size'])->toBeLessThanOrEqual($box['width']);
        }
    }
    $height = count($layout['title']['lines']) * $layout['title']['lineHeight'];
    if ($layout['subtitle']) {
        $height += $layout['subtitle']['gap'] + count($layout['subtitle']['lines']) * $layout['subtitle']['lineHeight'];
    }
    expect($layout['padTop'] + $height)->toBeLessThanOrEqual($box['height'] + 0.1);

    $text = ['x' => $box['x'], 'y' => $box['y'] + $layout['padTop'], 'width' => $box['width'], 'height' => $height];
    foreach ($layout['arrows'] as $arrow) {
        expect(signRectsOverlap($text, ['x' => $arrow['x'], 'y' => $arrow['y'], 'width' => $arrow['size'], 'height' => $arrow['size']]))
            ->toBeFalse("{$arrow['direction']} arrow over the text");
    }
    // Everything stays inside the border.
    $inner = $layout['border']['inset'] + $layout['border']['width'];
    expect($box['x'])->toBeGreaterThanOrEqual($inner)
        ->and($box['x'] + $box['width'])->toBeLessThanOrEqual($layout['page']['width'] - $inner);
}

test('signs are open to anyone signed in, and only to them', function () {
    $this->get('/setup/signs')->assertRedirect('/login');
    $this->get('/report/sign.pdf?title=HI&orientation=portrait')->assertRedirect('/login');

    $user = userWithPermissions();
    $this->actingAs($user)->get('/setup/signs')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Signs')->has('groups')->has('headers'));
    $this->actingAs($user)->get('/setup/signs/preview?title=SORTING&orientation=landscape&arrows[]=left')
        ->assertOk()->assertSee('SORTING')->assertSee('/setup/signs/fonts/LiberationSans-Bold.ttf', false);
    $this->actingAs($user)->get('/setup/signs/fonts/LiberationSans-Bold.ttf')->assertOk();
    $this->actingAs($user)->get('/setup/signs/fonts/..%2F..%2F.env')->assertNotFound();
});

test('Signs is on the Setup menu for everyone', function () {
    $tile = MenuItem::where('link_url', '/setup/signs')->first();

    expect($tile)->not->toBeNull()
        ->and($tile->permission_key)->toBeNull()
        ->and($tile->page->hashtag)->toBe('setup');
});

test('a sign needs a title, a known orientation, header and arrows', function (array $query) {
    $this->actingAs(userWithPermissions())->getJson('/setup/signs/preview?'.http_build_query($query))->assertUnprocessable();
})->with([
    'no title' => [['orientation' => 'portrait']],
    'bad orientation' => [['title' => 'X', 'orientation' => 'sideways']],
    'bad header' => [['title' => 'X', 'orientation' => 'portrait', 'header' => 'doom']],
    'bad arrow' => [['title' => 'X', 'orientation' => 'portrait', 'arrows' => ['north']]],
]);

test('every template fits its sign, with and without every arrow', function () {
    foreach (SignTemplates::all() as $sign) {
        expectSignFits($sign);
        expectSignFits(['arrows' => array_keys(SignLayout::ARROWS)] + $sign);
        expectSignFits(['orientation' => $sign['orientation'] === 'portrait' ? 'landscape' : 'portrait'] + $sign);
    }
});

test('awkward text still fits: one long word, many lines, a long note, other scripts', function (array $sign) {
    foreach ([[], ['left', 'up-right'], ['up', 'down', 'down-left'], array_keys(SignLayout::ARROWS)] as $arrows) {
        foreach (SignLayout::ORIENTATIONS as $orientation) {
            expectSignFits(['arrows' => $arrows, 'orientation' => $orientation] + $sign);
        }
    }
})->with([
    'one letter' => [['title' => 'A']],
    'long word' => [['title' => str_repeat('W', 60), 'header' => 'danger']],
    'many lines' => [['title' => "ONE\nTWO\nTHREE\nFOUR\nFIVE\nSIX", 'subtitle' => "a\nb\nc"]],
    'long note' => [['title' => 'NOTICE TO ALL VOLUNTEERS', 'subtitle' => str_repeat('Please read this carefully. ', 14), 'header' => 'notice']],
    'other scripts' => [['title' => 'Bienvenidos 歡迎 добро пожаловать', 'subtitle' => 'Señal ✅']],
]);

test('a title is set larger than its smaller text, and a short title is big', function () {
    $layout = SignLayout::make(['title' => 'A', 'subtitle' => 'Aisle', 'orientation' => 'portrait']);

    expect($layout['title']['size'])->toBeGreaterThan(200)
        ->and($layout['subtitle']['size'])->toBeLessThan($layout['title']['size']);
});

test('typed line breaks are kept; corners take the side bands that leave the larger title', function () {
    expect(SignLayout::make(['title' => "AISLE\nA", 'orientation' => 'landscape'])['title']['lines'])->toBe(['AISLE', 'A']);

    // A wide one-line title on a tall sheet loses less to top/bottom bands than to side bands.
    $layout = SignLayout::make(['title' => 'WATER', 'orientation' => 'portrait', 'arrows' => ['up-left']]);
    $plain = SignLayout::make(['title' => 'WATER', 'orientation' => 'portrait']);
    expect($layout['box']['width'])->toBe($plain['box']['width'])
        ->and($layout['box']['height'])->toBeLessThan($plain['box']['height']);
});

test('the sign PDF is one letter page in the chosen orientation with the text on it', function (string $orientation, string $size) {
    $path = tempnam(sys_get_temp_dir(), 'sign').'.pdf';
    app(SignController::class)->builder([
        'title' => 'WATCH FOR FORKLIFTS', 'subtitle' => 'Forklifts have the right of way',
        'header' => 'caution', 'orientation' => $orientation, 'arrows' => ['left', 'down-right'],
    ])->save($path);

    $info = pdfInfo($path);
    expect($info)->toContain('WeasyPrint')
        ->and($info)->toMatch('/Pages:\s+1\b/')
        ->and($info)->toMatch('/Page size:\s+'.$size.' pts/');
    $text = preg_replace('/\s+/', ' ', pdfText($path));
    expect($text)->toContain('CAUTION')->toContain('WATCH FOR')->toContain('FORKLIFTS')->toContain('right of way');
})->with([
    ['portrait', '612(\.\d+)? x 792(\.\d+)?'],
    ['landscape', '792(\.\d+)? x 612(\.\d+)?'],
])->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('a sign can be sent to a letter printer by anyone who can print', function () {
    Pdf::fake();
    Storage::fake(config('printing.disk'));
    $printer = makePrinter(printServer([['queue_name' => 'Q', 'state' => 'idle']]), 'Q', 'report', ['name' => 'Office']);

    $this->actingAs(userWithPermissions('print-documents'))->postJson('/json/print', [
        'document' => 'sign', 'printer_id' => $printer->id,
        'params' => ['title' => 'SORTING', 'orientation' => 'landscape', 'arrows' => ['right']],
    ])->assertCreated();

    expect(PrintJob::first()->title)->toBe('Sign: SORTING');
});
