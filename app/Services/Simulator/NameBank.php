<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Simulator;

/**
 * Fictional identities for the warehouse simulator: common first/last names,
 * "Adjective + adjective + noun" business names, partner organizations, and a
 * handful of real North Carolina counties/cities/zips so county- and
 * zip-grouped reports have something to group. Nothing here is a real person
 * or business; emails use example.org/.com and phones use the 555-01xx range
 * reserved for fiction.
 *
 * Every pick goes through SimRandom, so a seeded run produces the same names.
 */
class NameBank
{
    public const FIRST_NAMES = [
        'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda', 'David', 'Elizabeth',
        'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Charles', 'Karen',
        'Christopher', 'Nancy', 'Daniel', 'Lisa', 'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra',
        'Donald', 'Ashley', 'Steven', 'Kimberly', 'Paul', 'Emily', 'Andrew', 'Donna', 'Joshua', 'Michelle',
        'Kenneth', 'Carol', 'Kevin', 'Amanda', 'Brian', 'Melissa', 'George', 'Deborah', 'Timothy', 'Stephanie',
        'Ronald', 'Rebecca', 'Jason', 'Sharon', 'Edward', 'Laura', 'Jeffrey', 'Cynthia', 'Ryan', 'Kathleen',
        'Jacob', 'Amy', 'Gary', 'Angela', 'Nicholas', 'Shirley', 'Eric', 'Anna', 'Jonathan', 'Brenda',
        'Stephen', 'Pamela', 'Larry', 'Emma', 'Justin', 'Nicole', 'Scott', 'Helen', 'Brandon', 'Samantha',
        'Benjamin', 'Katherine', 'Samuel', 'Christine', 'Gregory', 'Debra', 'Alexander', 'Rachel', 'Frank', 'Carolyn',
        'Patrick', 'Janet', 'Raymond', 'Catherine', 'Jack', 'Maria', 'Dennis', 'Heather', 'Jerry', 'Diane',
        'Tyler', 'Ruth', 'Aaron', 'Julie', 'Jose', 'Olivia', 'Adam', 'Joyce', 'Nathan', 'Virginia',
        'Henry', 'Victoria', 'Zachary', 'Kelly', 'Douglas', 'Lauren', 'Peter', 'Christina', 'Kyle', 'Joan',
        'Noah', 'Evelyn', 'Ethan', 'Judith', 'Jeremy', 'Andrea', 'Walter', 'Hannah', 'Christian', 'Megan',
        'Keith', 'Cheryl', 'Roger', 'Jacqueline', 'Terry', 'Martha', 'Austin', 'Gloria', 'Sean', 'Teresa',
        'Gerald', 'Ann', 'Carl', 'Sara', 'Harold', 'Madison', 'Dylan', 'Frances', 'Arthur', 'Kathryn',
        'Lawrence', 'Janice', 'Jordan', 'Jean', 'Jesse', 'Abigail', 'Bryan', 'Alice', 'Billy', 'Judy',
        'Bruce', 'Julia', 'Gabriel', 'Grace', 'Joe', 'Denise', 'Logan', 'Amber', 'Albert', 'Marilyn',
        'Willie', 'Beverly', 'Alan', 'Danielle', 'Juan', 'Theresa', 'Wayne', 'Sophia', 'Elijah', 'Marie',
        'Randy', 'Diana', 'Roy', 'Brittany', 'Vincent', 'Natalie', 'Ralph', 'Isabella', 'Eugene', 'Charlotte',
        'Russell', 'Rose', 'Bobby', 'Alexis', 'Mason', 'Kayla', 'Philip', 'Lori', 'Louis', 'Tiffany',
        'Darnell', 'Latoya', 'Miguel', 'Guadalupe', 'Luis', 'Esperanza', 'Carlos', 'Yolanda', 'Hector', 'Marisol',
        'Tran', 'Mei', 'Raj', 'Priya', 'Omar', 'Fatima', 'Kwame', 'Amara', 'Ivan', 'Svetlana',
    ];

    public const LAST_NAMES = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
        'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
        'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
        'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores',
        'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell', 'Carter', 'Roberts',
        'Gomez', 'Phillips', 'Evans', 'Turner', 'Diaz', 'Parker', 'Cruz', 'Edwards', 'Collins', 'Reyes',
        'Stewart', 'Morris', 'Morales', 'Murphy', 'Cook', 'Rogers', 'Gutierrez', 'Ortiz', 'Morgan', 'Cooper',
        'Peterson', 'Bailey', 'Reed', 'Kelly', 'Howard', 'Ramos', 'Kim', 'Cox', 'Ward', 'Richardson',
        'Watson', 'Brooks', 'Chavez', 'Wood', 'James', 'Bennett', 'Gray', 'Mendoza', 'Ruiz', 'Hughes',
        'Price', 'Alvarez', 'Castillo', 'Sanders', 'Patel', 'Myers', 'Long', 'Ross', 'Foster', 'Jimenez',
        'Powell', 'Jenkins', 'Perry', 'Russell', 'Sullivan', 'Bell', 'Coleman', 'Butler', 'Henderson', 'Barnes',
        'Gonzales', 'Fisher', 'Vasquez', 'Simmons', 'Romero', 'Jordan', 'Patterson', 'Alexander', 'Hamilton', 'Graham',
        'Reynolds', 'Griffin', 'Wallace', 'Moreno', 'West', 'Cole', 'Hayes', 'Bryant', 'Herrera', 'Gibson',
        'Ellis', 'Tran', 'Medina', 'Aguilar', 'Stevens', 'Murray', 'Ford', 'Castro', 'Marshall', 'Owens',
        'Harrison', 'Fernandez', 'McDonald', 'Woods', 'Washington', 'Kennedy', 'Wells', 'Vargas', 'Henry', 'Chen',
        'Freeman', 'Webb', 'Tucker', 'Guzman', 'Burns', 'Crawford', 'Olson', 'Simpson', 'Porter', 'Hunter',
        'Gordon', 'Mendez', 'Silva', 'Shaw', 'Snyder', 'Mason', 'Dixon', 'Munoz', 'Hunt', 'Hicks',
        'Holmes', 'Palmer', 'Wagner', 'Black', 'Robertson', 'Boyd', 'Rose', 'Stone', 'Salazar', 'Fox',
        'Warren', 'Mills', 'Meyer', 'Rice', 'Schmidt', 'Garza', 'Daniels', 'Ferguson', 'Nichols', 'Stephens',
        'Soto', 'Weaver', 'Ryan', 'Gardner', 'Payne', 'Grant', 'Dunn', 'Kelley', 'Spencer', 'Hawkins',
        'Okafor', 'Abdi', 'Kowalski', 'Novak', 'Yoder', 'Beiler', 'Lindqvist', 'Haddad', 'Singh', 'Nakamura',
    ];

    public const ADJECTIVES = [
        'Golden', 'Silver', 'Blue', 'Red', 'Green', 'Bright', 'Swift', 'Quiet', 'Bold', 'Clever',
        'Gentle', 'Mighty', 'Noble', 'Prime', 'Royal', 'Rustic', 'Sunny', 'Stellar', 'Summit', 'True',
        'United', 'Vital', 'Wild', 'Eastern', 'Western', 'Northern', 'Southern', 'Central', 'Coastal', 'Mountain',
        'Prairie', 'River', 'Lakeside', 'Hilltop', 'Valley', 'Evergreen', 'Crimson', 'Amber', 'Ivory', 'Iron',
        'Copper', 'Granite', 'Cedar', 'Maple', 'Willow', 'Harvest', 'Liberty', 'Heritage', 'Pioneer', 'Frontier',
        'Keystone', 'Premier', 'Reliable', 'Friendly', 'Honest', 'Steady', 'Brisk', 'Lucky', 'Happy', 'Grand',
    ];

    public const NOUNS = [
        'Falcon', 'Harbor', 'Anchor', 'Beacon', 'Bridge', 'Canyon', 'Compass', 'Crown', 'Eagle', 'Forge',
        'Fox', 'Garden', 'Gate', 'Grove', 'Hawk', 'Horizon', 'Lantern', 'Lion', 'Meadow', 'Oak',
        'Orchard', 'Otter', 'Peak', 'Pine', 'Ridge', 'Sparrow', 'Spring', 'Star', 'Stone', 'Summit',
        'Thistle', 'Timber', 'Trail', 'Wagon', 'Wolf', 'Acorn', 'Barn', 'Bluff', 'Brook', 'Cardinal',
        'Clover', 'Creek', 'Dove', 'Elm', 'Field', 'Fern', 'Hollow', 'Heron', 'Junction', 'Kettle',
    ];

    public const DONOR_SUFFIXES = [
        'Supply', 'Foods', 'Hardware', 'Market', 'Wholesale', 'Outfitters', 'Trading Co.', 'Distribution',
        'Home Goods', 'Pharmacy', 'Furniture', 'Grocers', 'Industries', 'Textiles', 'Building Supply',
    ];

    public const PARTNER_TYPES = [
        'Community Church', 'Baptist Church', 'Methodist Church', 'Adventist Church', 'Food Pantry', 'Fire Department',
        'Community Center', 'Relief Station', 'Elementary Shelter', 'Neighbors Network', 'Outreach Ministry', 'CERT Team',
        'Volunteer Rescue Squad', 'Recreation Center', 'Disaster Response Team',
    ];

    // county => [[city, zip], ...] — real NC places, fictional everything else.
    public const COUNTIES = [
        'Iredell' => [['Statesville', '28625'], ['Statesville', '28677'], ['Mooresville', '28115'], ['Troutman', '28166']],
        'Catawba' => [['Hickory', '28601'], ['Hickory', '28602'], ['Newton', '28658'], ['Conover', '28613']],
        'Rowan' => [['Salisbury', '28144'], ['Salisbury', '28146'], ['China Grove', '28023']],
        'Alexander' => [['Taylorsville', '28681'], ['Hiddenite', '28636']],
        'Davie' => [['Mocksville', '27028'], ['Advance', '27006']],
        'Wilkes' => [['Wilkesboro', '28697'], ['North Wilkesboro', '28659']],
        'Burke' => [['Morganton', '28655'], ['Valdese', '28690']],
        'Cabarrus' => [['Concord', '28025'], ['Kannapolis', '28081']],
        'Lincoln' => [['Lincolnton', '28092'], ['Denver', '28037']],
        'Yadkin' => [['Yadkinville', '27055'], ['East Bend', '27018']],
    ];

    public const STREETS = [
        'Main St', 'Oak St', 'Maple Ave', 'Church St', 'Park Rd', 'Mill Rd', 'Highland Dr', 'Lakeview Dr', 'Pine St',
        'Elm St', 'Cedar Ln', 'Washington Ave', 'Broad St', 'Depot St', 'Industrial Dr', 'River Rd', 'Fairview Rd',
        'Walnut St', 'Meadow Ln', 'Court St', 'Salisbury Rd', 'Sharon School Rd', 'Old Mill Rd', 'Greenhill Rd',
    ];

    public function __construct(private SimRandom $rand) {}

    public function firstName(): string
    {
        return $this->rand->pick(self::FIRST_NAMES);
    }

    public function lastName(): string
    {
        return $this->rand->pick(self::LAST_NAMES);
    }

    /** "Golden Swift Falcon Supply" — adjective + adjective + noun + a business suffix. */
    public function businessName(): string
    {
        [$a, $b] = $this->rand->pickMany(self::ADJECTIVES, 2);

        return "{$a} {$b} ".$this->rand->pick(self::NOUNS).' '.$this->rand->pick(self::DONOR_SUFFIXES);
    }

    /** "Cedar Hollow Fire Department" — a distribution point / partner organization. */
    public function partnerName(): string
    {
        return $this->rand->pick(self::ADJECTIVES).' '.$this->rand->pick(self::NOUNS).' '.$this->rand->pick(self::PARTNER_TYPES);
    }

    /** @return array{county: string, city: string, zip: string, address: string, state: string} */
    public function place(): array
    {
        $county = $this->rand->pick(array_keys(self::COUNTIES));
        [$city, $zip] = $this->rand->pick(self::COUNTIES[$county]);

        return [
            'county' => $county,
            'city' => $city,
            'zip' => $zip,
            'state' => 'NC',
            'address' => $this->rand->int(100, 9899).' '.$this->rand->pick(self::STREETS),
        ];
    }

    public function phone(): string
    {
        return $this->rand->pick(['704', '828', '336', '980']).'-555-01'.str_pad((string) $this->rand->int(0, 99), 2, '0', STR_PAD_LEFT);
    }

    /** Lowercase, ascii-only email local/domain fragment from a display name. */
    public function slug(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '', strtolower($text)), '-');
    }
}
