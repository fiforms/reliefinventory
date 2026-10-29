<?php

require __DIR__.'/bootstrap.php';
use App\Models\PrintJob;

$j = PrintJob::find((int) $argv[1]);
echo $j ? $j->status.($j->error_message ? ' ('.$j->error_message.')' : '') : 'missing';
