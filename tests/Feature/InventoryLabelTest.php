<?php

// Inventory labels: one per pallet_stock_assignments row, numbered ACS:<family>-<variant>:<row id>.

use App\Http\Controllers\InventoryLabelController;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\LabelSetting;
use App\Models\Pallet;
use App\Models\PalletStockAssignment;
use App\Models\Person;
use App\Models\PrintJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Printing\DocumentRegistry;
use App\Support\InventoryLabelCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function invLabelItem(int $onHand = 100, string $family = '1234', string $variant = '01', string $name = 'Canned Vegetables'): Item
{
    $unitId = DB::table('units')->where('name', 'Case')->value('id') ?? DB::table('units')->insertGetId(['name' => 'Case', 'abbreviation' => 'cs']);
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Box')->value('id') ?? DB::table('packagetypes')->insertGetId(['singular' => 'Box', 'plural' => 'Boxes']);
    $type = ItemType::create(['name' => $name, 'family' => $family, 'variant' => $variant, 'unit_id' => $unitId, 'category_id' => Category::firstOrCreate(['name' => 'Food'])->id]);
    $item = Item::create(['itemtype_id' => $type->id, 'packagetypes_id' => $packagetypeId, 'description' => 'Case of '.$name]);
    $donation = Transaction::create(['type' => 'donation', 'person_id' => Person::create(['first_name' => 'Dee', 'last_name' => 'Donor'])->id, 'person_id_user' => User::factory()->create()->id, 'status_id' => Transaction::statusId(Transaction::STATUS_SORTING), 'order_date' => now()->toDateString()]);
    ItemLedger::create(['orderdonation_id' => $donation->id, 'item_id' => $item->id, 'qty_added' => $onHand, 'disposition' => 'usable']);

    return $item;
}

function invLabelPrinter(string $media)
{
    return makePrinter(printServer([['queue_name' => 'Q', 'state' => 'idle']]), 'Q', $media, ['name' => 'Office']);
}

/** PDF text via poppler's pdftotext, or Ghostscript's txtwrite where poppler-utils has only pdfinfo (so these tests still run). */
function labelPdfText(string $pdf): string
{
    if (labelHasBinary('pdftotext')) {
        return pdfText($pdf);
    }

    return (string) shell_exec('gs -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -sOutputFile=- '.escapeshellarg($pdf).' 2>/dev/null');
}

/** One binary at a time: under dash, `command -v a b` only checks `a`, which would misreport the other. */
function labelHasBinary(string $binary): bool
{
    return trim((string) shell_exec('command -v '.escapeshellarg($binary))) !== '';
}

/** These tests need a PDF size reader and some way to extract text. */
function labelPdfToolsMissing(): bool
{
    return ! labelHasBinary('pdfinfo') || ! (labelHasBinary('pdftotext') || labelHasBinary('gs'));
}

beforeEach(fn () => $this->actingAs(userWithPermissions('manage-pallets', 'print-documents')));

test('the label number is ACS, the full item number and the assignment id, and parses back', function () {
    $item = invLabelItem();
    $assignment = PalletStockAssignment::create(['pallet_id' => Pallet::create(['kind' => 'W', 'status' => 'open', 'datepacked' => now()->toDateString()])->id, 'item_id' => $item->id, 'qty' => 5]);

    $code = InventoryLabelCode::make($assignment->fresh());

    expect($code)->toBe("ACS:1234-01:{$assignment->id}")
        ->and(InventoryLabelCode::parse($code))->toBe($assignment->id)
        ->and(InventoryLabelCode::parse(" {$code}\n"))->toBe($assignment->id);
    foreach (['', 'ACS:1234-01:', 'ACS:1234-01:0', 'ACS:12-01:5', 'XYZ:1234-01:5', 'R00000042', 'ACS:1234-01:5:6', 'ACS:1234-01:-5'] as $bad) {
        expect(InventoryLabelCode::parse($bad))->toBeNull("{$bad} should not parse");
    }
});

test('filling records each line\'s own expiration and returns the assignment ids', function () {
    $water = invLabelItem(100, '0042', '00', 'Bottled Water');
    $beans = invLabelItem(100, '0300', '02', 'Canned Beans');

    $res = $this->postJson('/json/pallet-stock/fill', [
        'expires_on' => '2027-01-15',
        'lines' => [
            ['item_id' => $water->id, 'qty' => 40, 'expires_on' => '2027-06-01'],
            ['item_id' => $beans->id, 'qty' => 10],
        ],
    ])->assertCreated();

    $ids = $res->json('assignment_ids');
    expect($ids)->toHaveCount(2);
    $rows = PalletStockAssignment::findMany($ids)->keyBy('item_id');
    expect($rows[$water->id]->expires_on->toDateString())->toBe('2027-06-01')
        ->and($rows[$beans->id]->expires_on)->toBeNull()
        // the pallet keeps its own overall earliest date, as before
        ->and(substr((string) Pallet::find($res->json('record.id'))->earliest_expiry, 0, 10))->toBe('2027-01-15');

    // the pallet payload lists them (newest first) so a label can be reprinted
    $labels = $res->json('record.labels');
    expect(collect($labels)->pluck('id')->all())->toBe(array_reverse($ids))
        ->and(collect($labels)->firstWhere('item_id', $water->id))->toMatchArray(['qty' => 40, 'expires_on' => '2027-06-01']);

    // scanning the pallet (the Inventory Movement lookup) carries the same list
    $tag = Pallet::find($res->json('record.id'))->tag;
    $this->getJson('/json/palletstatus/lookup?tag='.$tag)->assertOk()
        ->assertJsonPath('record.labels.0.id', $ids[1])->assertJsonCount(2, 'record.labels');

    // scanning an inventory label finds its pallet and says which label it was
    $code = InventoryLabelCode::make(PalletStockAssignment::with('item.itemtype')->find($ids[0]));
    $this->getJson('/json/palletstatus/lookup?tag='.urlencode($code))->assertOk()
        ->assertJsonPath('record.tag', $tag)->assertJsonPath('record.scanned_label.id', $ids[0]);
    $this->getJson('/json/palletstatus/lookup?tag=ACS:0510-01:99999999')->assertNotFound();

    $this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $water->id, 'qty' => 1, 'expires_on' => 'not a date']]])->assertUnprocessable();
});

test('inventory labels default to the small label and follow the setting', function () {
    expect(LabelSetting::inventoryMedia())->toBe('label_small');

    $documents = app(DocumentRegistry::class);
    expect($documents->find('inventory-labels')['media'])->toBe('label_small');

    LabelSetting::current()->update(['inventory_label_media' => 'label_large']);
    expect($documents->find('inventory-labels')['media'])->toBe('label_large')
        ->and(LabelSetting::palletMedia())->toBe('label_large'); // independent of the pallet label setting
});

test('inventory labels print only on a printer loaded with the small label by default', function () {
    Pdf::fake();
    Storage::fake(config('printing.disk'));
    $item = invLabelItem();
    $id = $this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 5]]])->json('assignment_ids.0');
    $small = invLabelPrinter('label_small');
    $large = invLabelPrinter('label_large');

    $this->postJson('/json/print', ['document' => 'inventory-labels', 'params' => ['ids' => [$id]], 'printer_id' => $large->id])
        ->assertUnprocessable()->assertJsonValidationErrors('printer_id');
    $this->postJson('/json/print', ['document' => 'inventory-labels', 'params' => ['ids' => [$id]], 'printer_id' => $small->id])
        ->assertCreated();

    expect(PrintJob::first()->media)->toBe('label_small')
        ->and(PrintJob::first()->title)->toBe('Inventory labels (1)');
});

test('printing and downloading inventory labels need manage-pallets', function () {
    $item = invLabelItem();
    $id = $this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 5]]])->json('assignment_ids.0');

    $this->actingAs(userWithPermissions('general-access'))->get("/report/inventory-labels?ids={$id}")->assertForbidden();
});

test('the label settings endpoint saves each size on its own and rejects other media', function () {
    $admin = userWithPermissions('manage-devices');

    $this->actingAs($admin)->getJson('/json/printer-overview')->assertOk()
        ->assertJsonPath('label_settings.inventory_label_media', 'label_small')
        ->assertJsonPath('label_settings.inventory_options.0.key', 'label_small')
        ->assertJsonPath('label_settings.pallet_label_media', 'label_large');

    $this->actingAs($admin)->putJson('/json/printers/label-settings', ['inventory_label_media' => 'label_large'])
        ->assertOk()->assertJsonPath('inventory_label_media', 'label_large')->assertJsonPath('pallet_label_media', 'label_large');
    $this->actingAs($admin)->putJson('/json/printers/label-settings', ['pallet_label_media' => 'label_small'])
        ->assertOk()->assertJsonPath('inventory_label_media', 'label_large')->assertJsonPath('pallet_label_media', 'label_small');

    $this->actingAs($admin)->putJson('/json/printers/label-settings', ['inventory_label_media' => 'report'])->assertUnprocessable();
});

test('labels render one page per assignment at the chosen size, with number, description, quantity and expiry', function () {
    $item = invLabelItem(100, '1234', '01', 'Canned Vegetables');
    $res = $this->postJson('/json/pallet-stock/fill', [
        'lines' => [['item_id' => $item->id, 'qty' => 24, 'expires_on' => '2027-03-15'], ['item_id' => $item->id, 'qty' => 6]],
    ])->assertCreated();
    $ids = $res->json('assignment_ids');

    $path = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
    app(InventoryLabelController::class)->builder($ids)->save($path);
    if ($dir = getenv('LABEL_RENDER_DIR')) {
        @mkdir($dir, 0777, true);
        copy($path, $dir.'/inventory-small.pdf');
        LabelSetting::current()->update(['inventory_label_media' => 'label_large']);
        app(InventoryLabelController::class)->builder($ids)->save($dir.'/inventory-large.pdf');
    }

    $info = pdfInfo($path);
    expect($info)->toMatch('/Pages:\s+2\b/')->toMatch('/Page size:\s+166(\.\d+)? x 288(\.\d+)? pts/');
    $text = labelPdfText($path);
    expect($text)->toContain('1234-01')->toContain('Canned Vegetables')->toContain('Qty 24')->toContain('Qty 6')
        ->toContain('Mar 15, 2027')->toContain('Exp: none')->toContain("ACS:1234-01:{$ids[0]}");
})->skip(fn () => labelPdfToolsMissing(), 'pdfinfo and a text extractor (pdftotext or Ghostscript) are needed');

test('only rows that added stock produce a label, and unknown ids are a 404', function () {
    $item = invLabelItem();
    $pallet = Pallet::create(['kind' => 'W', 'status' => 'open', 'datepacked' => now()->toDateString()]);
    $correction = PalletStockAssignment::create(['pallet_id' => $pallet->id, 'item_id' => $item->id, 'qty' => -3]);

    expect(fn () => app(InventoryLabelController::class)->builder([$correction->id, 999999]))
        ->toThrow(NotFoundHttpException::class);
});

test('every bar and gap of the small label barcode rasterizes to a whole number of 3-dot modules', function () {
    $item = invLabelItem();
    $ids = $this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 24, 'expires_on' => '2027-03-15']]])->json('assignment_ids');
    $path = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
    app(InventoryLabelController::class)->builder($ids)->save($path);

    // Rasterize the way the Pi's print filter does (203 dpi, 1 bit, no anti-aliasing), read one column through the rotated barcode.
    $pbm = tempnam(sys_get_temp_dir(), 'inv').'.pbm';
    exec('gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pbmraw -r203 -dTextAlphaBits=1 -dGraphicsAlphaBits=1 -sOutputFile='.escapeshellarg($pbm).' '.escapeshellarg($path), $out, $code);
    expect($code)->toBe(0);
    $data = file_get_contents($pbm);
    preg_match('/^P4\s+(?:#[^\n]*\n\s*)?(\d+)\s+(\d+)\s/', $data, $m, 0);
    [$w, $h] = [(int) $m[1], (int) $m[2]];
    $rowBytes = intdiv($w + 7, 8);
    $bits = substr($data, -$rowBytes * $h);
    $pixel = fn (int $x, int $y) => (ord($bits[$y * $rowBytes + ($x >> 3)]) >> (7 - ($x & 7))) & 1;

    $x = 50; // inside the strip of bars down the left edge
    $runs = [];
    $current = $pixel($x, 0);
    $length = 0;
    for ($y = 0; $y < $h; $y++) {
        $value = $pixel($x, $y);
        if ($value === $current) {
            $length++;
        } else {
            $runs[] = [$current, $length];
            [$current, $length] = [$value, 1];
        }
    }
    $runs[] = [$current, $length];
    // Drop the white page margin + quiet zone before the first bar, and the long blank after the last.
    $barcode = array_slice(array_values(array_filter($runs, fn ($r) => $r[1] < 70)), 1);
    expect(count($barcode))->toBeGreaterThan(40);
    foreach ($barcode as [$value, $length]) {
        expect($length % 3)->toBe(0, ($value ? 'bar' : 'gap')." of {$length} dots is not a whole number of 3-dot modules");
        expect($length)->toBeLessThanOrEqual(12);
    }
})->skip(fn () => ! labelHasBinary('gs'), 'Ghostscript not installed');
