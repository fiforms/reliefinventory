<?php

// Types + activates the queue the client reported and queues one stored-PDF job through the same
// broadcast hook the app uses. Args: queue_name. Prints "<job id> <enqueue epoch>".
require __DIR__.'/bootstrap.php';
use App\Models\ManagedDevice;
use App\Models\PrintJob;
use App\Services\PrintBroadcaster;
use Illuminate\Support\Facades\Storage;

$dev = ManagedDevice::where('name', 'E2E box')->latest('id')->first() ?? exit(fwrite(STDERR, "no E2E device\n") | 3);
$printer = $dev->printers()->where('queue_name', $argv[1])->first() ?? exit(fwrite(STDERR, "queue not reported yet\n") | 4);
$printer->forceFill(['media' => 'report', 'active' => true])->save();
$path = 'print-jobs/e2e-'.microtime(true).'.pdf';
Storage::disk(config('printing.disk'))->put($path, "%PDF-1.4\n% relief-inventory e2e test page\n%%EOF\n");
$job = PrintJob::create([
    'printer_id' => $printer->id, 'kiosk_location_id' => $printer->kiosk_location_id, 'media' => 'report',
    'title' => 'E2E test page', 'pdf_path' => $path, 'copies' => 1,
]);
PrintBroadcaster::jobChanged($job);
echo $job->id.' '.sprintf('%.3f', microtime(true));
