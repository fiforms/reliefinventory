<?php

// Creates a site (if none) and a client pairing code (as the Devices page makes) in the test DB; prints the code.
require __DIR__.'/bootstrap.php';
use App\Models\KioskLocation;
use App\Services\Federation\TrustService;
use Illuminate\Support\Facades\Cache;

// The pairing endpoint throttles per IP (and keeps a 10-minute attempt counter) in the cache, so repeated
// e2e runs would start getting 429s. bootstrap.php has already proven this is the disposable test DB.
Cache::flush();

$loc = KioskLocation::first() ?? KioskLocation::create(['name' => 'E2E Site']);
[$code] = TrustService::createPairingCode('client', ['print.share'], null, $loc->id, null);
echo $code;
