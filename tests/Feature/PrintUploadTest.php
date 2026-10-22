<?php

// "Print a PDF" on the Printers page: an administrator uploads any PDF and sends it to a printer.

use App\Models\PrintJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

const UPLOAD_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

function uploadPrinter(string $media = 'label_small', array $overrides = [], bool $online = true)
{
    return makePrinter(printServer([['queue_name' => 'Q', 'state' => 'idle']], $online), 'Q', $media, array_merge(['name' => 'Dock'], $overrides));
}

function uploadPdf(string $name = 'schedule.pdf', string $content = UPLOAD_PDF): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

beforeEach(fn () => Storage::fake(config('printing.disk')));

test('a print manager uploads a PDF and it is queued as is on the chosen printer', function () {
    $printer = uploadPrinter('label_small');
    $user = userWithPermissions('manage-print-queue');

    $res = $this->actingAs($user)->post('/json/print-upload', ['file' => uploadPdf(), 'printer_id' => $printer->id, 'copies' => 3], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('title', 'schedule.pdf')->assertJsonPath('printer.name', 'Dock');

    $job = PrintJob::findOrFail($res->json('id'));
    expect($job->document)->toBe('uploaded-pdf')
        ->and($job->media)->toBe('label_small') // the printer's own paper, whatever the PDF is
        ->and($job->copies)->toBe(3)
        ->and($job->requested_by)->toBe($user->id)
        ->and($job->status)->toBe(PrintJob::STATUS_QUEUED)
        ->and(Storage::disk(config('printing.disk'))->get($job->pdf_path))->toBe(UPLOAD_PDF);
});

test('only someone who manages the print queue can upload', function () {
    $printer = uploadPrinter();

    $this->actingAs(userWithPermissions('print-documents'))
        ->post('/json/print-upload', ['file' => uploadPdf(), 'printer_id' => $printer->id], ['Accept' => 'application/json'])->assertForbidden();
    auth()->logout();
    $this->post('/json/print-upload', ['file' => uploadPdf(), 'printer_id' => $printer->id], ['Accept' => 'application/json'])->assertUnauthorized();
    expect(PrintJob::count())->toBe(0);
});

test('anything that is not a PDF is refused, whatever it is called', function () {
    $printer = uploadPrinter();
    $user = userWithPermissions('manage-print-queue');

    foreach ([
        'script.pdf' => "<?php echo 'hi';",
        'page.pdf' => '<html><body>not a pdf</body></html>',
        'empty.pdf' => '',
        'macro.pdf' => str_repeat('x', 2000)."\n%PDF-1.4 hidden after the first kilobyte",
    ] as $name => $content) {
        $this->actingAs($user)->post('/json/print-upload', ['file' => uploadPdf($name, $content), 'printer_id' => $printer->id], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }
    expect(PrintJob::count())->toBe(0);
});

test('a missing file, a file over the size limit, and a bad copies count are refused', function () {
    $printer = uploadPrinter();
    $user = userWithPermissions('manage-print-queue');
    config(['printing.upload_max_kb' => 1]);

    $this->actingAs($user)->postJson('/json/print-upload', ['printer_id' => $printer->id])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->actingAs($user)->post('/json/print-upload', ['file' => uploadPdf('big.pdf', UPLOAD_PDF.str_repeat('0', 4096)), 'printer_id' => $printer->id], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    config(['printing.upload_max_kb' => 10240]);
    foreach ([0, 100, 'many'] as $copies) {
        $this->actingAs($user)->post('/json/print-upload', ['file' => uploadPdf(), 'printer_id' => $printer->id, 'copies' => $copies], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('copies');
    }
    expect(PrintJob::count())->toBe(0);
});

test('inactive, untyped, unsited and unknown printers are refused', function () {
    $user = userWithPermissions('manage-print-queue');

    foreach ([
        uploadPrinter('label_small', ['active' => false])->id,
        uploadPrinter('label_small', ['media' => null])->id,
        uploadPrinter('label_small', ['kiosk_location_id' => null])->id,
        999999,
    ] as $printerId) {
        $this->actingAs($user)->post('/json/print-upload', ['file' => uploadPdf(), 'printer_id' => $printerId], ['Accept' => 'application/json'])->assertUnprocessable();
    }
    expect(PrintJob::count())->toBe(0);
});

test('the file name is shown as the title with any path and control characters removed', function () {
    $printer = uploadPrinter();
    $user = userWithPermissions('manage-print-queue');

    $title = fn (string $name) => $this->actingAs($user)->post('/json/print-upload', ['file' => uploadPdf($name), 'printer_id' => $printer->id], ['Accept' => 'application/json'])->json('title');

    expect($title('../../etc/passwd.pdf'))->toBe('passwd.pdf')
        ->and($title('C:\\Users\\Bob\\invoice.pdf'))->toBe('invoice.pdf')
        ->and($title("bell\x07 and\x1b[31m red.pdf"))->toBe('bell and[31m red.pdf')
        ->and(mb_strlen($title(str_repeat('a', 300).'.pdf')))->toBeLessThanOrEqual(120);
});

test('a printer whose server is offline still takes the job, which waits in the queue', function () {
    $printer = uploadPrinter('report', [], online: false);

    $this->actingAs(userWithPermissions('manage-print-queue'))
        ->post('/json/print-upload', ['file' => uploadPdf(), 'printer_id' => $printer->id], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('printer.ready', false);
    expect(PrintJob::first()->status)->toBe(PrintJob::STATUS_QUEUED);
});

test('the printers page tells the screen whether the upload card is allowed', function () {
    $this->actingAs(userWithPermissions('manage-devices', 'manage-print-queue'))->get('/setup/printers')
        ->assertInertia(fn ($page) => $page->where('canUploadPdf', true));
    $this->actingAs(userWithPermissions('manage-devices'))->get('/setup/printers')
        ->assertInertia(fn ($page) => $page->where('canUploadPdf', false));
});
