<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * A readable browser and operating system from a User-Agent string, so an admin approving a device for PIN login
 * can tell "Chrome 141 on Windows" from "Safari on iPad" at a glance. Best effort by design: browsers lie (every
 * Chromium browser claims to be Chrome and Safari), so order matters below, and anything unrecognised is null
 * rather than a guess. The raw string is kept beside it.
 */
class UserAgent
{
    /** @return array{browser: ?string, os: ?string} */
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;

        return ['browser' => self::browser($ua), 'os' => self::os($ua)];
    }

    private static function browser(string $ua): ?string
    {
        // Most specific first: Edge, Opera, Samsung Internet and Chrome-on-iOS all also say "Chrome" or "Safari".
        $patterns = [
            'Edge' => '/Edg(?:e|A|iOS)?\/(\d+)/',
            'Opera' => '/(?:OPR|Opera)\/(\d+)/',
            'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
            'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/',
            'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/',
            'Safari' => '/Version\/(\d+)[\d.]* .*Safari\//',
        ];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua, $m)) {
                return $name.' '.$m[1];
            }
        }

        return null;
    }

    private static function os(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'iPad') => 'iPadOS',
            str_contains($ua, 'iPhone') => 'iOS',
            (bool) preg_match('/Android (\d+)/', $ua, $m) => 'Android '.$m[1],
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Windows NT 10.0') => 'Windows 10/11',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
    }
}
