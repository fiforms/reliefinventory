<?php

// routes/web.php is only an index of routes/web/*.php (dev/notes/ROUTES.md). These tests keep it that way.

test('routes/web.php defines no routes of its own', function () {
    $code = file_get_contents(base_path('routes/web.php'));

    expect($code)->not->toMatch('/\bRoute::/');
});

test('every routes/web/*.php file is required by routes/web.php, and the reverse', function () {
    $files = array_map(fn ($f) => basename($f, '.php'), glob(base_path('routes/web/*.php')));
    preg_match_all("#require __DIR__\\.'/web/([a-z0-9_-]+)\\.php';#", file_get_contents(base_path('routes/web.php')), $m);

    sort($files);
    $required = $m[1];
    sort($required);

    expect($required)->toBe($files);
});

test('no method and URI is registered twice, so file order never decides a match', function () {
    $seen = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $key = $method.' '.$route->uri();
            expect($seen)->not->toHaveKey($key);
            $seen[$key] = true;
        }
    }
});
