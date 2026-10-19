<?php
// Creates a site (if none) and a 6-digit pairing code in the test DB; prints the code.
require __DIR__.'/bootstrap.php';
use App\Models\DevicePairingCode;
use App\Models\KioskLocation;
use Illuminate\Support\Facades\Cache;

// The pairing endpoint throttles per IP (and keeps a 10-minute attempt counter) in the cache, so repeated
// e2e runs would start getting 429s. bootstrap.php has already proven this is the disposable test DB.
Cache::flush();

$loc = KioskLocation::first() ?? KioskLocation::create(['name' => 'E2E Site']);
$code = DevicePairingCode::create([
    'code' => (string) random_int(100000, 999999), 'kiosk_location_id' => $loc->id, 'expires_at' => now()->addMinutes(10),
]);
echo $code->code;
