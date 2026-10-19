<?php
// Removes everything the e2e run created (the "E2E box" device, its printers and jobs, stored PDFs).
require __DIR__.'/bootstrap.php';
use App\Models\ManagedDevice;
use App\Models\PrintJob;
use Illuminate\Support\Facades\Storage;

foreach (ManagedDevice::where('name', 'E2E box')->get() as $dev) {
    foreach ($dev->printers as $p) {
        foreach (PrintJob::where('printer_id', $p->id)->get() as $j) {
            Storage::disk(config('printing.disk'))->delete($j->pdf_path);
            $j->delete();
        }
        $p->delete();
    }
    $dev->delete();
}
echo "cleaned\n";
