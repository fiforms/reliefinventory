<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

/**
 * Deterministic, keyword/pattern-based screen for feedback-report text —
 * NOT an LLM call. It has to work even when the thing that would otherwise
 * read this content (an AI assistant triaging bug reports) is exactly what
 * a submission is trying to manipulate, so it can't depend on that same
 * assistant being the one judging its own input.
 *
 * This never blocks submission or triage — see FeedbackReportController.
 * It only sets a non-blocking flag (mirrors Transaction's
 * donor_identification_pending) so a human triager sees a warning before
 * anyone — human or AI — acts on the content. False positives cost a
 * triager a few seconds; false negatives cost nothing extra, since this is
 * one layer among several (see CLAUDE.md's "Untrusted content" note).
 */
class FeedbackContentScanner
{
    /**
     * label => regex. Kept as named groups (not one giant alternation) so
     * flaggedReason() can report which one actually matched.
     */
    private const PATTERNS = [
        'credential/secret reference' => '/\b(api[ _-]?key|access[ _-]?token|auth[ _-]?token|secret[ _-]?key|credentials?|password)\b/i',
        'sensitive system path' => '/(\/etc\/passwd|\/etc\/shadow|~?\/\.ssh|id_rsa|id_ed25519|\.env\b)/i',
        'exfiltration-shaped phrasing' => '/(hex[- ]?encod|base64[- ]?encod|hidden in the (page|code)|never (be )?(shown|surfaced) in the (ui|ui)|so (that )?(anybody|anyone) (can|could) see|copy (these|this|the) (file|files|credentials|keys))/i',
    ];

    /**
     * Returns a short reason string if any pattern matched, or null if the
     * text looks ordinary. Checks the full raw text once per pattern —
     * cheap enough to run on every submission and every triage update.
     */
    public function scan(?string $text): ?string
    {
        if (! $text) {
            return null;
        }

        $matched = [];
        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text)) {
                $matched[] = $label;
            }
        }

        return $matched ? implode('; ', $matched) : null;
    }
}
