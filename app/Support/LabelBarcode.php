<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use Milon\Barcode\Facades\DNS1DFacade;

/**
 * Code 128 for the narrow thermal labels (a 203 dpi SATO WS408), drawn so that every bar and gap is a whole number of printer
 * dots. CSS px are 96 per inch, so one dot is 96/203 px; a module of N dots is N x that.
 *
 * The labels are rasterized without anti-aliasing (Ghostscript in the Pi's print filter), where any pixel a bar touches turns
 * black. A bar landing exactly on dot boundaries (plus a little float error) grew by a dot while its neighbouring gap shrank
 * by one — 3-dot modules came out as 4-dot bars and 2-dot gaps, which scanners that judge element widths can misread. So each
 * bar's edges are pulled a quarter dot inward, keeping them inside their own pixels.
 *
 * DNS1D also ends every symbol with two zero-width bars; those are dropped (inset, they would get a negative width).
 */
class LabelBarcode
{
    public const DPI = 203;

    public static function pxPerDot(): float
    {
        return 96 / self::DPI;
    }

    /** Number of modules (the symbol's width without quiet zones) for `$code`. */
    public static function modules(string $code): int
    {
        preg_match('/width:([\d.]+)px/', DNS1DFacade::getBarcodeHTML($code, 'C128', 1, 10), $m);

        return (int) round((float) $m[1]);
    }

    /** The bars as HTML (no quiet zone, no text), `$moduleDots` printer dots per module and `$heightPx` tall. */
    public static function html(string $code, int $moduleDots, int $heightPx): string
    {
        $dot = self::pxPerDot();
        $inset = 0.25 * $dot;
        $html = DNS1DFacade::getBarcodeHTML($code, 'C128', $moduleDots * $dot, $heightPx);
        $html = preg_replace('/<div style="[^"]*width:0(?:\.0+)?px;[^"]*">&nbsp;<\/div>\n?/', '', $html) ?? $html;

        return preg_replace_callback(
            '/width:([\d.]+)px;height:([\d.]+)px;position:absolute;left:([\d.]+)px/',
            fn ($m) => sprintf('width:%spx;height:%spx;position:absolute;left:%spx', round((float) $m[1] - 2 * $inset, 4), $m[2], round((float) $m[3] + $inset, 4)),
            $html
        ) ?? $html;
    }
}
