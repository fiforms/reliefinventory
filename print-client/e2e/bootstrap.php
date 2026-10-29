<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Boots the real Laravel app for the e2e helpers, but REFUSES to continue unless it is pointed at the
// disposable test database. The helpers create and delete rows; they must never touch real data.
// (run.sh sets DB_DATABASE; a real environment variable beats .env.)
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
if ($db !== 'test_reliefinventory') {
    fwrite(STDERR, "REFUSING: connected to '$db', not test_reliefinventory\n");
    exit(99);
}
