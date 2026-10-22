<?php

// The pallet label size setting (LabelSetting): which media pallet labels are laid out for and printed on.

use App\Http\Controllers\PalletReportController;
use App\Models\LabelSetting;
use App\Models\Pallet;
use App\Models\PrintJob;
use App\Services\Printing\DocumentRegistry;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

function labelSizePrinter(string $media)
{
    return makePrinter(printServer([['queue_name' => 'Q', 'state' => 'idle']]), 'Q', $media, ['name' => 'Office']);
}

function labelSizePrint($user, $printer, int $palletId)
{
    return test()->actingAs($user)->postJson('/json/print', [
        'document' => 'pallet-label', 'params' => ['id' => $palletId], 'printer_id' => $printer->id,
    ]);
}

function labelSizePallet(): Pallet
{
    return Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
}

function useLabelMedia(string $media): void
{
    LabelSetting::current()->update(['pallet_label_media' => $media]);
}

test('pallet labels default to the large label and every label document follows the setting', function () {
    expect(LabelSetting::palletMedia())->toBe('label_large');

    useLabelMedia('label_small');

    $documents = app(DocumentRegistry::class)->all();
    foreach (['pallet-label', 'donation-pallet-labels', 'preprint-pallet-labels'] as $key) {
        expect($documents[$key]['media'])->toBe('label_small');
    }
    expect($documents['inventory-report']['media'])->toBe('report');
});

test('a media key that was removed from config falls back to the default', function () {
    LabelSetting::current()->forceFill(['pallet_label_media' => 'label_gone'])->save();

    expect(LabelSetting::palletMedia())->toBe('label_large');
});

test('the small label is only for the narrow layout', function () {
    expect(LabelSetting::palletLabelIsCompact())->toBeFalse();
    useLabelMedia('label_small');
    expect(LabelSetting::palletLabelIsCompact())->toBeTrue();
});

test('a pallet label only goes to a printer loaded with the chosen size', function () {
    Pdf::fake();
    Storage::fake(config('printing.disk'));
    $large = labelSizePrinter('label_large');
    $small = labelSizePrinter('label_small');
    $user = userWithPermissions('print-documents', 'manage-pallets');
    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);

    labelSizePrint($user, $large, $pallet->id)->assertCreated();
    labelSizePrint($user, $small, $pallet->id)->assertUnprocessable()->assertJsonValidationErrors('printer_id');

    useLabelMedia('label_small');

    labelSizePrint($user, $small, $pallet->id)->assertCreated();
    labelSizePrint($user, $large, $pallet->id)->assertUnprocessable()->assertJsonValidationErrors('printer_id');
    expect(PrintJob::pluck('media')->all())->toBe(['label_large', 'label_small']);
});

test('an administrator picks the size on the printers page, with no change for anyone else', function () {
    $admin = userWithPermissions('manage-devices');

    $this->actingAs($admin)->getJson('/json/printer-overview')->assertOk()
        ->assertJsonPath('label_settings.pallet_label_media', 'label_large')
        ->assertJsonPath('label_settings.options.0.key', 'label_large')
        ->assertJsonPath('label_settings.options.1.key', 'label_small');

    $this->actingAs($admin)->putJson('/json/printers/label-settings', ['pallet_label_media' => 'label_small'])
        ->assertOk()->assertJsonPath('pallet_label_media', 'label_small');
    expect(LabelSetting::palletMedia())->toBe('label_small');

    $this->actingAs($admin)->putJson('/json/printers/label-settings', ['pallet_label_media' => 'report'])
        ->assertUnprocessable()->assertJsonValidationErrors('pallet_label_media');
    $this->actingAs($admin)->putJson('/json/printers/label-settings', [])->assertUnprocessable();

    $other = userWithPermissions('manage-pallets');
    $this->actingAs($other)->putJson('/json/printers/label-settings', ['pallet_label_media' => 'label_large'])->assertForbidden();
    expect(LabelSetting::palletMedia())->toBe('label_small');
});

test('labels render at 2.312 x 4 in when the small size is chosen', function () {
    useLabelMedia('label_small');
    $pallets = collect(range(1, 2))->map(fn () => labelSizePallet());

    $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
    app(PalletReportController::class)->preprintLabelsBuilder($pallets->pluck('id')->all())->save($path);

    $info = pdfInfo($path);
    expect($info)->toMatch('/Pages:\s+2\b/')
        ->and($info)->toMatch('/Page size:\s+166(\.\d+)? x 288(\.\d+)? pts/');
    foreach ($pallets as $pallet) {
        expect(pdfText($path))->toContain($pallet->tag);
    }

    $single = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
    app(PalletReportController::class)->singleLabelBuilder($pallets[0]->id)->save($single);
    expect(pdfInfo($single))->toMatch('/Pages:\s+1\b/')->toMatch('/Page size:\s+166(\.\d+)? x 288(\.\d+)? pts/');
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');
