<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * US state / territory names and their two-letter postal codes — people.state
 * is a 2-character column, so an imported "North Carolina" has to become "NC".
 */
class UsStates
{
    public const CODES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
        'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        'AS' => 'American Samoa', 'GU' => 'Guam', 'MP' => 'Northern Mariana Islands', 'PR' => 'Puerto Rico',
        'VI' => 'Virgin Islands', 'UM' => 'United States Minor Outlying Islands',
        'AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific',
    ];

    /** "nc", "N.C.", "North Carolina" → "NC"; null when it isn't a US state or territory. */
    public static function code(string $value): ?string
    {
        $value = trim($value);
        $letters = strtoupper(str_replace(['.', ' '], '', $value));
        if (isset(self::CODES[$letters])) {
            return $letters;
        }
        $name = strtolower(preg_replace('/\s+/', ' ', $value));
        foreach (self::CODES as $code => $full) {
            if (strtolower($full) === $name) {
                return $code;
            }
        }

        return null;
    }
}
