<?php

// MULTI_WAREHOUSE.md §3: a plain link (an <a href>, window.open, location.href) can't send X-RI-Warehouse, so a
// download opened from a tab whose browser was switched to another warehouse would be served from that one.
// Every such link to a report, CSV, file or backup must name its warehouse: `$withWarehouse(...)` in a template,
// `withWarehouse(...)` in script. (`download-href` props are fine: PrintMenu adds it.)

/** @return array<string, string> relative path => contents, for every Vue page, component and layout */
function vueSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (in_array($file->getExtension(), ['vue', 'js'], true)) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

test('every plain download link names its warehouse', function () {
    // A link that fetches data or a file, as opposed to one that opens a page.
    $fetches = '(/report/|/json/|\.csv|/download|download_url|photoUrl)';

    $offenders = [];
    foreach (vueSources() as $path => $source) {
        // PrintMenu and the helper itself, and the Help pages (static text, no links to data).
        if (str_ends_with($path, 'Components/PrintMenu.vue') || str_ends_with($path, 'resources/js/bootstrap.js')) {
            continue;
        }
        foreach (explode("\n", $source) as $i => $line) {
            $isLink = preg_match('/(?<![-\w])(:?href)=/', $line) && ! str_contains($line, '<Link')
                || preg_match('/window\.open\(|location\.(href|assign)\s*=|location\.assign\(/', $line);
            if (! $isLink || ! preg_match($fetches, $line)) {
                continue;
            }
            // Lines that only mention a path in a comment or navigate to a fixed page are not matched by $fetches.
            if (str_contains($line, 'withWarehouse(')) {
                continue;
            }
            $offenders[] = $path.':'.($i + 1).': '.trim($line);
        }
    }

    expect($offenders)->toBe([], "Wrap these in withWarehouse():\n".implode("\n", $offenders));
});
