<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use Illuminate\Support\Str;

/**
 * One Help guide, read from its Markdown source (resources/help/<locale>/<slug>.md). This is the only copy of a
 * guide's text: the screen page (Pages/Help/Guide.vue) and the PDF (reports/help/guide.blade.php) both render it.
 *
 * Source layout: the first paragraph is the intro line under the title; each `## ` heading starts a section (a card
 * on screen, a ruled block in print). Anything between the intro and the first heading is a section without a heading.
 * Raw HTML in the source is stripped, so a guide can carry an HTML comment (the license header) but no markup.
 */
class HelpGuide
{
    /** @param  string  $intro  inline HTML  @param  list<string>  $sections  HTML, one per section */
    private function __construct(public readonly string $intro, public readonly array $sections) {}

    public static function path(string $slug, string $locale = 'en'): string
    {
        return resource_path("help/$locale/$slug.md");
    }

    public static function load(string $slug, string $locale = 'en'): self
    {
        $chunks = preg_split('/^(?=## )/m', file_get_contents(self::path($slug, $locale)));
        $html = array_map(fn (string $md) => trim(Str::markdown($md, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ])), $chunks);

        // The preamble's first paragraph is the intro; whatever follows it is a heading-less section.
        $intro = '';
        if (preg_match('#^<p>(.*?)</p>\s*#s', $html[0], $m)) {
            $intro = $m[1];
            $html[0] = substr($html[0], strlen($m[0]));
        }

        return new self($intro, array_values(array_filter($html, fn (string $h) => $h !== '')));
    }
}
