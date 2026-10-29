<?php

// dev/SCHEMA.md is generated from the migrations; this fails when a migration changed without regenerating it.

test('dev/SCHEMA.md matches the migrations', function () {
    $files = [];
    foreach (['', 'control/', 'accounts/'] as $dir) {
        foreach (glob(__DIR__.'/../../database/migrations/'.$dir.'*.php') as $f) {
            $files[] = $dir.basename($f).':'.sha1_file($f);
        }
    }
    sort($files);

    preg_match('/<!-- migrations-stamp: ([0-9a-f]{40}) -->/', file_get_contents(__DIR__.'/../../dev/SCHEMA.md'), $m);

    expect($m[1] ?? null)->toBe(sha1(implode("\n", $files)), 'dev/SCHEMA.md is stale: run dev/scripts/schema-doc.sh and commit it');
});
