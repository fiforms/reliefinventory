<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * Lays out one Letter-size sign (SignController, reports/sign.blade.php): a
 * border, an optional safety header band, arrows at the edges and corners, and
 * the title and smaller text set as large as they fit in the space left over.
 *
 * WeasyPrint can't shrink text to fit, so the fitting happens here: the sign
 * prints in the bundled Liberation Sans (resources/fonts/liberation), whose
 * glyph widths are in metrics.php, and the text is wrapped into lines here and
 * printed line by line (nowrap), so the PDF breaks exactly where this measured.
 * Everything is in points (72 per inch), measured from the top-left of the page.
 *
 * Arrows sit in bands along the edges they point to, and the text never enters
 * a band. A corner arrow needs a band on one of its two sides; both choices are
 * tried and the one that leaves the larger title wins (on a portrait sign that
 * is usually top/bottom, on a landscape one left/right).
 */
class SignLayout
{
    /** Arrow direction => SVG rotation of a right-pointing arrow (y runs down). */
    public const ARROWS = [
        'up-left' => 225, 'up' => 270, 'up-right' => 315,
        'left' => 180, 'right' => 0,
        'down-left' => 135, 'down' => 90, 'down-right' => 45,
    ];

    /** Optional header band: label, band colour, label colour. */
    public const HEADERS = [
        'danger' => ['label' => 'DANGER', 'background' => '#C8102E', 'color' => '#FFFFFF'],
        'warning' => ['label' => 'WARNING', 'background' => '#F26722', 'color' => '#000000'],
        'caution' => ['label' => 'CAUTION', 'background' => '#FFD200', 'color' => '#000000'],
        'notice' => ['label' => 'NOTICE', 'background' => '#0059A8', 'color' => '#FFFFFF'],
        'safety' => ['label' => 'SAFETY FIRST', 'background' => '#008751', 'color' => '#FFFFFF'],
    ];

    public const ORIENTATIONS = ['portrait', 'landscape'];

    private const MARGIN = 25.2;       // page edge to the border (0.35 in, inside any printer's unprintable edge)

    private const BORDER = 8;

    private const PADDING = 14.4;      // border to content

    private const HEADER_HEIGHT = 86.4;

    private const BAND = 136.8;        // depth of an arrow band (1.9 in)

    private const ARROW = 115.2;       // arrow drawing, centred in its band square (1.6 in)

    private const TITLE_LINE = 1.12;   // line height, x font size

    private const SUBTITLE_LINE = 1.25;

    private const SUBTITLE_RATIO = 0.42;

    private const SUBTITLE_MAX = 66;

    private const TITLE_MAX = 400;

    private const TITLE_MIN = 10;

    /** Lines are filled to this share of the width, for kerning and rounding. */
    private const FILL = 0.96;

    private static ?array $metrics = null;

    /**
     * @param  array{title: string, subtitle?: ?string, header?: ?string, orientation?: ?string, arrows?: ?array}  $sign
     */
    public static function make(array $sign): array
    {
        $portrait = ($sign['orientation'] ?? 'landscape') === 'portrait';
        $pageW = $portrait ? 612.0 : 792.0;
        $pageH = $portrait ? 792.0 : 612.0;
        $header = self::HEADERS[$sign['header'] ?? ''] ?? null;
        $arrows = array_values(array_intersect(array_keys(self::ARROWS), $sign['arrows'] ?? []));
        $title = self::clean($sign['title'] ?? '');
        $subtitle = self::clean($sign['subtitle'] ?? '');

        $inset = self::MARGIN + self::BORDER;
        $innerW = $pageW - 2 * $inset;
        $headerBand = null;
        $top = $inset;
        if ($header) {
            $label = $header['label'];
            $size = min(self::HEADER_HEIGHT * 0.62, $innerW * self::FILL / max(0.01, self::width($label, 'bold')));
            $headerBand = $header + ['x' => $inset, 'y' => $inset, 'width' => $innerW, 'height' => self::HEADER_HEIGHT,
                'size' => floor($size)];
            $top += self::HEADER_HEIGHT;
        }

        // The frame arrows sit in; the text goes in what the bands leave of it.
        $frame = [
            'x' => $inset + self::PADDING, 'y' => $top + self::PADDING,
            'width' => $innerW - 2 * self::PADDING, 'height' => $pageH - $inset - self::PADDING - ($top + self::PADDING),
        ];

        $need = [
            'left' => in_array('left', $arrows), 'right' => in_array('right', $arrows),
            'up' => in_array('up', $arrows), 'down' => in_array('down', $arrows),
        ];
        $corners = array_filter($arrows, fn ($a) => str_contains($a, '-'));
        $options = $corners ? ['sides', 'ends'] : ['sides'];

        $best = null;
        foreach ($options as $option) {
            $bands = $need;
            foreach ($corners as $corner) {
                [$vertical, $horizontal] = explode('-', $corner);
                $bands[$option === 'sides' ? $horizontal : $vertical] = true;
            }
            $box = [
                'x' => $frame['x'] + ($bands['left'] ? self::BAND : 0),
                'y' => $frame['y'] + ($bands['up'] ? self::BAND : 0),
                'width' => $frame['width'] - ($bands['left'] ? self::BAND : 0) - ($bands['right'] ? self::BAND : 0),
                'height' => $frame['height'] - ($bands['up'] ? self::BAND : 0) - ($bands['down'] ? self::BAND : 0),
            ];
            $text = self::fit($title, $subtitle, $box);
            if (! $best || $text['title']['size'] > $best['text']['title']['size']) {
                $best = ['box' => $box, 'text' => $text];
            }
        }

        $offset = (self::BAND - self::ARROW) / 2;
        $place = [
            'left' => [$frame['x'], $frame['y'] + ($frame['height'] - self::BAND) / 2],
            'right' => [$frame['x'] + $frame['width'] - self::BAND, $frame['y'] + ($frame['height'] - self::BAND) / 2],
            'up' => [$frame['x'] + ($frame['width'] - self::BAND) / 2, $frame['y']],
            'down' => [$frame['x'] + ($frame['width'] - self::BAND) / 2, $frame['y'] + $frame['height'] - self::BAND],
            'up-left' => [$frame['x'], $frame['y']],
            'up-right' => [$frame['x'] + $frame['width'] - self::BAND, $frame['y']],
            'down-left' => [$frame['x'], $frame['y'] + $frame['height'] - self::BAND],
            'down-right' => [$frame['x'] + $frame['width'] - self::BAND, $frame['y'] + $frame['height'] - self::BAND],
        ];

        return [
            'orientation' => $portrait ? 'portrait' : 'landscape',
            'page' => ['width' => $pageW, 'height' => $pageH],
            'border' => ['inset' => self::MARGIN, 'width' => self::BORDER, 'color' => $header['background'] ?? '#000000'],
            'header' => $headerBand,
            'arrows' => array_map(fn ($a) => [
                'direction' => $a, 'rotate' => self::ARROWS[$a], 'size' => self::ARROW,
                'x' => $place[$a][0] + $offset, 'y' => $place[$a][1] + $offset,
            ], $arrows),
            'box' => $best['box'],
            'title' => $best['text']['title'],
            'subtitle' => $best['text']['subtitle'],
            'padTop' => $best['text']['padTop'],
        ];
    }

    /** The largest title (and its smaller text) that fits the box, centred down it. */
    private static function fit(string $title, string $subtitle, array $box): array
    {
        $width = $box['width'] * self::FILL;
        $start = (int) min(self::TITLE_MAX, floor($box['height'] / self::TITLE_LINE));
        // Words are only broken if nothing fits without (a word too long for the sign even
        // at the smallest size).
        foreach ([false, true] as $breakWords) {
            for ($size = max($start, self::TITLE_MIN); $size >= self::TITLE_MIN; $size--) {
                $titleLines = self::wrap($title, 'bold', $size, $width, $breakWords);
                $sub = $subtitle === '' ? 0 : min(self::SUBTITLE_MAX, round($size * self::SUBTITLE_RATIO, 1));
                $subLines = $sub ? self::wrap($subtitle, 'regular', $sub, $width, $breakWords) : [];
                $height = self::blockHeight(count($titleLines), $size, count($subLines), $sub);
                $result = compact('titleLines', 'size', 'subLines', 'sub', 'height', 'breakWords');
                if ($height <= $box['height'] && self::fits($titleLines, 'bold', $size, $width) && self::fits($subLines, 'regular', $sub, $width)) {
                    break 2;
                }
            }
        }

        // A title held back by its width (a long word) leaves height spare: let the smaller
        // text use it, up to a little over half the title so it still reads as secondary.
        if ($result['sub']) {
            $cap = min(self::SUBTITLE_MAX, floor($result['size'] * 0.55));
            for ($sub = $cap; $sub > $result['sub']; $sub--) {
                $subLines = self::wrap($subtitle, 'regular', $sub, $width, $result['breakWords']);
                $height = self::blockHeight(count($result['titleLines']), $result['size'], count($subLines), $sub);
                if ($height <= $box['height'] && self::fits($subLines, 'regular', $sub, $width)) {
                    $result = ['sub' => $sub, 'subLines' => $subLines, 'height' => $height] + $result;
                    break;
                }
            }
        }

        $result['titleLines'] = self::balance($title, 'bold', $result['size'], $width, $result['breakWords'], $result['titleLines']);
        if ($result['sub']) {
            $result['subLines'] = self::balance($subtitle, 'regular', $result['sub'], $width, $result['breakWords'], $result['subLines']);
        }

        return [
            'title' => ['size' => $result['size'], 'lineHeight' => round($result['size'] * self::TITLE_LINE, 2), 'lines' => $result['titleLines']],
            'subtitle' => $result['sub'] ? [
                'size' => $result['sub'], 'lineHeight' => round($result['sub'] * self::SUBTITLE_LINE, 2),
                'gap' => round($result['sub'] * 0.6, 2), 'lines' => $result['subLines'],
            ] : null,
            'padTop' => round(max(0, ($box['height'] - $result['height']) / 2), 2),
        ];
    }

    private static function blockHeight(int $titleLines, float $size, int $subLines, float $sub): float
    {
        $height = $titleLines * $size * self::TITLE_LINE;

        return $subLines ? $height + $sub * 0.6 + $subLines * $sub * self::SUBTITLE_LINE : $height;
    }

    /**
     * Greedy word wrap at $width points. A line typed by the person stays a
     * line of its own. A single word wider than the line is left whole (fits()
     * then rejects the size, so the caller shrinks until it fits) unless
     * $breakWords, when it is cut into pieces that fit.
     *
     * @return array<int, string>
     */
    public static function wrap(string $text, string $weight, float $size, float $width, bool $breakWords = false): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            $words = preg_split('/ +/', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY);
            if ($breakWords) {
                $words = array_merge([], ...array_map(fn ($w) => self::pieces($w, $weight, $size, $width), $words));
            }
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && self::width($candidate, $weight) * $size > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The same number of lines, but evened out: rewrapped at the narrowest width that
     * still gives that many, so a sign doesn't end on one stranded word.
     *
     * @return array<int, string>
     */
    private static function balance(string $text, string $weight, float $size, float $width, bool $breakWords, array $lines): array
    {
        if (count($lines) < 2) {
            return $lines;
        }
        [$low, $high] = [$width / 2, $width];
        while ($high - $low > 1) {
            $mid = ($low + $high) / 2;
            $tried = self::wrap($text, $weight, $size, $mid, $breakWords);
            if (count($tried) <= count($lines) && self::fits($tried, $weight, $size, $mid)) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return self::wrap($text, $weight, $size, $high, $breakWords);
    }

    /** @return array<int, string> $word cut into pieces no wider than $width (at least one character each). */
    private static function pieces(string $word, string $weight, float $size, float $width): array
    {
        $pieces = [];
        $piece = '';
        foreach (mb_str_split($word) as $char) {
            if ($piece !== '' && self::width($piece.$char, $weight) * $size > $width) {
                $pieces[] = $piece;
                $piece = '';
            }
            $piece .= $char;
        }

        return [...$pieces, $piece];
    }

    private static function fits(array $lines, string $weight, float $size, float $width): bool
    {
        foreach ($lines as $line) {
            if (self::width($line, $weight) * $size > $width) {
                return false;
            }
        }

        return true;
    }

    /** Width of $text in em (multiply by the font size for points). */
    public static function width(string $text, string $weight): float
    {
        self::$metrics ??= require resource_path('fonts/liberation/metrics.php');
        $widths = self::$metrics[$weight];
        $em = 0.0;
        foreach (mb_str_split($text) as $char) {
            // Anything the table doesn't know (another script, an emoji) prints in a
            // fallback font; count it as a wide glyph so it can't overflow.
            $em += $widths[mb_ord($char)] ?? 1.0;
        }

        return $em;
    }

    /** Trimmed, with Windows/Mac line endings, tabs and runs of blank lines tidied. */
    private static function clean(?string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], (string) $text);
        $text = preg_replace('/\n{2,}/', "\n", $text);

        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }
}
