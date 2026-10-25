<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * Design Votes: the polls people vote on at /vote/{slug} to choose between UX designs. Each option is a live demo
 * (a Vue component registered under the same slug and key in resources/js/DesignPolls/index.js), so a poll is
 * defined here in code, not in an admin page. Only whether a poll is open, and the votes, are in the database
 * (design_polls / design_poll_votes). Adding a poll: an entry here, its demo components, and a design_polls row
 * (a migration, created closed).
 */
class DesignPolls
{
    /** "I am a…" choices on the vote form (optional). */
    public const VOTER_ROLES = [
        'warehouse' => 'Warehouse staff',
        'volunteer' => 'Volunteer',
        'partner' => 'Partner organization',
        'other' => 'Other',
    ];

    public const POLLS = [
        'delivery-days' => [
            'title' => 'Delivery days on a request',
            'question' => 'Which way of choosing delivery days makes the most sense to you?',
            'intro' => 'When a partner asks for a delivery, we record which days they can take it. Some days may be '
                .'better than others: a partner might accept Tuesday, Wednesday or Thursday but prefer Tuesday. '
                .'Try each design below — tap the days as if you were entering a request — then pick the one you like best.',
            'options' => [
                'pick' => [
                    'label' => 'Pick the days',
                    'description' => 'Tap each day the partner can accept. No preferred day. The simplest choice.',
                ],
                'cycle' => [
                    'label' => 'Tap to cycle',
                    'description' => 'Each tap moves a day along: cannot → can accept → preferred (★) → cannot.',
                ],
                'star' => [
                    'label' => 'Pick, then star',
                    'description' => 'Tap the days the partner can accept, then tap the ☆ on a day to make it preferred.',
                ],
                'grid' => [
                    'label' => 'Day grid',
                    'description' => 'One row per day. Choose Can\'t, OK or Preferred for each.',
                ],
                'rows' => [
                    'label' => 'Two rows',
                    'description' => 'One row of days for "can accept" and one for "preferred". A preferred day is also accepted.',
                ],
            ],
        ],
    ];

    public static function find(string $slug): ?array
    {
        return isset(self::POLLS[$slug]) ? ['slug' => $slug] + self::POLLS[$slug] : null;
    }

    /** @return array<int, array> every poll, in registry order */
    public static function all(): array
    {
        return array_map(fn ($slug) => self::find($slug), array_keys(self::POLLS));
    }
}
