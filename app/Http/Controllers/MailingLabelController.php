<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\County;
use App\Models\Person;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Mailing labels for a partner, donor, volunteer or "other" mailing, on
 * Avery 8160 / 5160 sheets (Letter, 3 x 10, 2-5/8 x 1 in) for an office
 * printer.
 *
 * Who is in an audience:
 *  - partner: the Partner role, or any request (order) on record
 *  - donor: the Donor role, or any donation on record
 *  - volunteer: people.is_volunteer
 *  - other: none of those, and no role at all (so staff logins aren't swept in)
 * The roles are the tags the mailing-list CSV import sets
 * (App\Services\Import\MailingListImporter); history counts too, so people
 * entered through Receiving / Order Entry are included without tagging them.
 * System records (Unknown Donor) and training trainees are never listed.
 *
 * Only people with a full address (street, city, state, zip) get a label; the
 * list shows the rest so they can be fixed on the People page. The selection
 * reaches the PDF as the filters plus either `ids` (the people checked) or
 * `exclude` (the people unchecked), whichever is shorter, so the download URL
 * stays short for a long list.
 */
class MailingLabelController extends Controller
{
    public const AUDIENCES = [
        'partner' => 'Partners',
        'donor' => 'Donors',
        'volunteer' => 'Volunteers',
        'other' => 'Other (no tag)',
    ];

    /** Avery 8160 / 5160. */
    public const PER_SHEET = 30;

    public const FILTER_RULES = [
        'audience' => 'required|in:partner,donor,volunteer,other',
        'county_id' => 'nullable|integer|exists:counties,id',
        'q' => 'nullable|string|max:100',
        'sort' => 'nullable|in:name,zip',
    ];

    public const PRINT_RULES = self::FILTER_RULES + [
        'ids' => 'nullable|array|max:5000',
        'ids.*' => 'integer',
        'exclude' => 'nullable|array|max:5000',
        'exclude.*' => 'integer',
        'start' => 'nullable|integer|min:1|max:'.self::PER_SHEET,
    ];

    public function index(Request $request)
    {
        $filters = $request->validate(self::FILTER_RULES);

        return response()->json([
            'filters' => $filters,
            'records' => $this->people($filters)->map(fn (Person $p) => $this->record($p))->values(),
            'options' => [
                'audiences' => self::AUDIENCES,
                'counties' => County::orderBy('county')->get(['id', 'county', 'state']),
                'per_sheet' => self::PER_SHEET,
            ],
        ]);
    }

    public function pdf(Request $request)
    {
        return $this->labelsBuilder($request->validate(self::PRINT_RULES));
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function labelsBuilder(array $params): PdfBuilder
    {
        $people = $this->people($params)->filter(fn (Person $p) => $this->mailable($p));
        if (! empty($params['ids'])) {
            $ids = array_flip(array_map('intval', $params['ids']));
            $people = $people->filter(fn (Person $p) => isset($ids[$p->id]));
        } elseif (! empty($params['exclude'])) {
            $exclude = array_flip(array_map('intval', $params['exclude']));
            $people = $people->reject(fn (Person $p) => isset($exclude[$p->id]));
        }

        $start = (int) ($params['start'] ?? 1);
        // Blank cells for the labels already used on a partly-used first sheet.
        $cells = array_merge(array_fill(0, $start - 1, null), $people->map(fn (Person $p) => self::labelLines($p))->values()->all());

        return Pdf::view('reports.mailing_labels', [
            'sheets' => array_chunk($cells, self::PER_SHEET),
            'count' => $people->count(),
        ])
            ->driver('weasyprint')
            ->format('letter')
            ->name('mailing-labels-'.$params['audience'].'-'.now()->format('Y-m-d').'.pdf');
    }

    /** The lines printed on one label: name, organization, street line(s), "City, ST 12345". */
    public static function labelLines(Person $person): array
    {
        $name = trim(($person->first_name ?? '').' '.($person->last_name ?? ''));
        $org = trim($person->organization ?? '');
        $lines = [];
        if ($name !== '') {
            $lines[] = $name;
        }
        if ($org !== '' && strcasecmp($org, $name) !== 0) {
            $lines[] = $org;
        }
        foreach (preg_split('/\r\n|\r|\n/', trim((string) $person->address)) as $street) {
            if (trim($street) !== '') {
                $lines[] = trim($street);
            }
        }
        $lines[] = trim($person->city).', '.strtoupper(trim($person->state)).' '.trim($person->zip);

        return $lines;
    }

    private function mailable(Person $person): bool
    {
        return trim((string) $person->address) !== '' && trim((string) $person->city) !== ''
            && trim((string) $person->state) !== '' && trim((string) $person->zip) !== '';
    }

    private function record(Person $person): array
    {
        $roles = $person->roles->pluck('name');

        return [
            'id' => $person->id,
            'name' => $person->full_name,
            'organization' => $person->organization,
            'address' => $person->address,
            'city' => $person->city,
            'state' => $person->state,
            'zip' => $person->zip,
            'county' => $person->county?->county,
            'mailable' => $this->mailable($person),
            'lines' => $this->mailable($person) ? self::labelLines($person) : [],
            'tags' => array_values(array_filter([
                ($roles->contains('Partner') || $person->has_requests) ? 'Partner' : null,
                ($roles->contains('Donor') || $person->has_donations) ? 'Donor' : null,
                $person->is_volunteer ? 'Volunteer' : null,
            ])),
        ];
    }

    /** @return Collection<int, Person> */
    private function people(array $filters): Collection
    {
        $roleIds = Role::whereIn('name', ['Partner', 'Donor'])->pluck('id', 'name');
        $hasRole = fn (Builder $q, string $role) => $q->whereHas('roles', fn ($r) => $r->where('roles.id', $roleIds[$role] ?? 0));
        $hasHistory = fn (Builder $q, string $type) => $q->whereHas('orderDonations', fn ($t) => $t->where('type', $type));

        $query = Person::query()
            ->with(['roles:id,name', 'county:id,county'])
            ->withExists([
                'orderDonations as has_requests' => fn ($t) => $t->where('type', 'order'),
                'orderDonations as has_donations' => fn ($t) => $t->where('type', 'donation'),
            ])
            ->whereNull('system_key')
            ->where('is_trainee', false);

        match ($filters['audience']) {
            'partner' => $query->where(fn ($q) => $hasRole($q, 'Partner')->orWhere(fn ($h) => $hasHistory($h, 'order'))),
            'donor' => $query->where(fn ($q) => $hasRole($q, 'Donor')->orWhere(fn ($h) => $hasHistory($h, 'donation'))),
            'volunteer' => $query->where('is_volunteer', true),
            'other' => $query->where('is_volunteer', false)
                ->whereDoesntHave('roles')
                ->whereDoesntHave('orderDonations', fn ($t) => $t->whereIn('type', ['order', 'donation'])),
        };

        if (! empty($filters['county_id'])) {
            $query->where('county_id', $filters['county_id']);
        }
        if (($q = trim($filters['q'] ?? '')) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhere('organization', 'like', $like)->orWhere('city', 'like', $like)->orWhere('zip', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like]));
        }

        if (($filters['sort'] ?? 'name') === 'zip') {
            $query->orderBy('zip');
        }

        return $query
            ->orderByRaw("COALESCE(NULLIF(last_name, ''), NULLIF(organization, ''), first_name)")
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
    }
}
