<?php

// Makes a pairing code for a backup client in the test DB and prints it. Args: [grants,comma,separated] (default operator.full_read).
require dirname(__DIR__, 2).'/print-client/e2e/bootstrap.php';
use App\Services\Federation\TrustService;
use Illuminate\Support\Facades\Cache;

// The pairing endpoint throttles per IP in the cache; bootstrap.php has already proven this is the disposable test DB.
Cache::flush();
$grants = isset($argv[1]) ? array_filter(explode(',', $argv[1])) : ['operator.full_read'];
[$code] = TrustService::createPairingCode('backup_client', $grants, null, null, null);
echo $code;
