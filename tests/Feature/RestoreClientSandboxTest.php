<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Services\DatabaseTools;

/*
 * A restored dump is untrusted. The SQL is limited by the database user's grants (ProvisionerTest); these tests
 * cover the other half: the client program must not be usable to run commands or touch files in the container.
 */

function runClientWith(string $sql): array
{
    $tools = app(DatabaseTools::class);
    $process = $tools->process($tools->clientCommand());
    $process->setInput($sql);
    $process->run();

    return [$process->isSuccessful(), $process->getOutput().$process->getErrorOutput()];
}

it('refuses the client commands that reach the file system', function () {
    $dir = sys_get_temp_dir().'/ri-sandbox-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/secret.txt", "line-from-a-file\n");

    // Each statement is on its own line, as a dump would have it; a refused one must not stop the rest being refused too.
    $lines = [
        "system touch $dir/system-ran",
        "\\! touch $dir/bang-ran",
        "tee $dir/tee-out",
        "source $dir/secret.txt",
        'CREATE TEMPORARY TABLE probe (line TEXT);',
        "LOAD DATA LOCAL INFILE '$dir/secret.txt' INTO TABLE probe;",
        'SELECT line FROM probe;',
    ];
    [, $output] = runClientWith(implode("\n", $lines)."\n");

    expect(file_exists("$dir/system-ran"))->toBeFalse()
        ->and(file_exists("$dir/bang-ran"))->toBeFalse()
        ->and(file_exists("$dir/tee-out"))->toBeFalse()
        ->and($output)->not->toContain('line-from-a-file');

    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
});

it('still loads ordinary SQL', function () {
    [$ok, $output] = runClientWith("SELECT 'plain-sql-works' AS v;\n");

    expect($ok)->toBeTrue()->and($output)->toContain('plain-sql-works');
});
