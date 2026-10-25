<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DesignPoll;
use App\Models\DesignPollIdea;
use App\Models\DesignPollVote;
use App\Services\TurnstileService;
use App\Support\DesignPolls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Milon\Barcode\DNS2D;

/**
 * Design Votes (App\Support\DesignPolls). The public side is /vote/{slug} (Pages/DesignVote.vue): anyone with the
 * link, signed in or not, tries each option's live demo and votes for one. One vote per browser, kept by an
 * HttpOnly cookie token (stored only as a hash); a signed-in person's vote also follows them to another device.
 * Voting again replaces the earlier vote while the poll is open. Results are shown on the vote page only once the
 * poll is closed, so early votes don't sway later ones; the admin page (/setup/design-votes, manage-design-votes)
 * sees them live. The page also has a "Have another idea?" box (storeIdea) for anything that comes to mind.
 *
 * Built like TraineeSignupController for a room on one Wi-Fi network: the POST limit is 80 a minute per IP and
 * Turnstile (when enabled) keeps bots out.
 */
class DesignVoteController extends Controller
{
    private const COOKIE = 'design_vote_token';

    public function __construct(private TurnstileService $turnstile) {}

    public function show(Request $request, string $slug)
    {
        $poll = DesignPolls::find($slug) ?? abort(404);
        $state = DesignPoll::forSlug($slug);
        $vote = $this->existingVote($request, $slug);
        $turnstileEnabled = $this->turnstile->enabled();
        $unavailable = $turnstileEnabled && $this->turnstile->misconfigured();

        return Inertia::render('DesignVote', [
            'poll' => $this->pollPayload($poll),
            'open' => $state->is_open,
            'my_vote' => $vote?->only(['option_key', 'voter_name', 'comment']),
            'results' => $state->is_open ? null : self::results($slug),
            'turnstile_enabled' => $turnstileEnabled && ! $unavailable,
            'turnstile_site_key' => $turnstileEnabled && ! $unavailable ? $this->turnstile->siteKey() : null,
            'turnstile_unavailable' => $unavailable,
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $poll = DesignPolls::find($slug) ?? abort(404);
        if (! DesignPoll::forSlug($slug)->is_open) {
            return response()->json(['message' => 'Voting on this has closed.'], 403);
        }
        if ($refused = $this->turnstileUnavailable()) {
            return $refused;
        }

        $data = $request->validate([
            'option_key' => ['required', Rule::in(array_keys($poll['options']))],
            'voter_name' => 'nullable|string|max:100',
            'comment' => 'nullable|string|max:2000',
        ] + $this->turnstileRule());
        $this->verifyTurnstile($request, $data);

        $token = $this->cookieToken($request) ?? Str::random(40);
        $values = [
            'option_key' => $data['option_key'],
            'voter_name' => filled($data['voter_name'] ?? null) ? trim($data['voter_name']) : null,
            'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
            'ip_address' => $request->ip(),
        ];
        if (Auth::id()) {
            $values['person_id'] = Auth::id();
        }

        DB::transaction(function () use ($request, $slug, $token, $values) {
            $vote = $this->existingVote($request, $slug, $token, lock: true);
            $vote
                ? $vote->update($values)
                : DesignPollVote::create($values + ['poll_slug' => $slug, 'voter_token_hash' => hash('sha256', $token)]);
        });

        return response()->json(['option_key' => $values['option_key']], 201)
            ->cookie(self::COOKIE, $token, 60 * 24 * 365, '/', null, null, true, false, 'lax');
    }

    /**
     * "Have another idea?": free text about this question or anything else. Accepted whether or not the person
     * voted and even after voting has closed — the point is to catch an idea when it comes. Each one is kept.
     */
    public function storeIdea(Request $request, string $slug)
    {
        DesignPolls::find($slug) ?? abort(404);
        if ($refused = $this->turnstileUnavailable()) {
            return $refused;
        }

        $data = $request->validate([
            'idea' => 'required|string|max:4000',
            'voter_name' => 'nullable|string|max:100',
        ] + $this->turnstileRule());
        $this->verifyTurnstile($request, $data);

        DesignPollIdea::create([
            'poll_slug' => $slug,
            'idea' => trim($data['idea']),
            'person_id' => Auth::id(),
            'voter_name' => filled($data['voter_name'] ?? null) ? trim($data['voter_name']) : null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['saved' => true], 201);
    }

    /** Admin page data: every poll with its state, live results, comments and voting link. */
    public function status()
    {
        return response()->json(['polls' => array_map(function (array $poll) {
            $state = DesignPoll::forSlug($poll['slug']);
            $url = route('design-vote', $poll['slug']);

            return $this->pollPayload($poll) + [
                'open' => $state->is_open,
                'opened_at' => $state->opened_at?->toIso8601String(),
                'closed_at' => $state->closed_at?->toIso8601String(),
                'url' => $url,
                'qr_svg' => (new DNS2D)->getBarcodeSVG($url, 'QRCODE', 5, 5),
                'results' => self::results($poll['slug']),
                'comments' => DesignPollVote::where('poll_slug', $poll['slug'])->whereNotNull('comment')->latest('updated_at')
                    ->get(['option_key', 'voter_name', 'comment', 'updated_at']),
                'ideas' => DesignPollIdea::where('poll_slug', $poll['slug'])->latest()->get(['idea', 'voter_name', 'created_at']),
            ];
        }, DesignPolls::all())]);
    }

    public function setOpen(Request $request, string $slug)
    {
        DesignPolls::find($slug) ?? abort(404);
        $open = $request->validate(['open' => 'required|boolean'])['open'];

        $state = DesignPoll::forSlug($slug);
        $state->is_open = $open;
        $open ? $state->opened_at = now() : $state->closed_at = now();
        $state->save();

        return $this->status();
    }

    public function csv(string $slug)
    {
        $poll = DesignPolls::find($slug) ?? abort(404);
        $votes = DesignPollVote::where('poll_slug', $slug)->orderBy('created_at')->get();
        $ideas = DesignPollIdea::where('poll_slug', $slug)->orderBy('created_at')->get();

        return response()->streamDownload(function () use ($poll, $votes, $ideas) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Type', 'Sent', 'Updated', 'Choice', 'Name', 'Comment or idea']);
            foreach ($votes as $vote) {
                fputcsv($out, [
                    'Vote',
                    $vote->created_at?->toDateTimeString(),
                    $vote->updated_at?->toDateTimeString(),
                    $poll['options'][$vote->option_key]['label'] ?? $vote->option_key,
                    $vote->voter_name,
                    $vote->comment,
                ]);
            }
            foreach ($ideas as $idea) {
                fputcsv($out, ['Idea', $idea->created_at?->toDateTimeString(), null, null, $idea->voter_name, $idea->idea]);
            }
            fclose($out);
        }, 'design-vote-'.$slug.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Votes per option (every option listed, most votes first).
     *
     * @return array{total: int, options: array<int, array{key: string, votes: int, percent: int}>}
     */
    public static function results(string $slug): array
    {
        $poll = DesignPolls::find($slug);
        // Only the options the poll still has: a design dropped after voting started stops counting.
        $keys = array_keys($poll['options']);
        $counts = DesignPollVote::where('poll_slug', $slug)->whereIn('option_key', $keys)
            ->groupBy('option_key')->selectRaw('option_key, count(*) as n')->pluck('n', 'option_key');
        $total = (int) $counts->sum();

        $options = collect($keys)
            ->map(fn ($key) => [
                'key' => $key,
                'votes' => (int) ($counts[$key] ?? 0),
                'percent' => $total ? (int) round(100 * ($counts[$key] ?? 0) / $total) : 0,
            ])
            ->sortByDesc('votes')->values()->all();

        return ['total' => $total, 'options' => $options];
    }

    private function pollPayload(array $poll): array
    {
        return [
            'slug' => $poll['slug'],
            'title' => $poll['title'],
            'question' => $poll['question'],
            'intro' => $poll['intro'],
            'options' => collect($poll['options'])->map(fn ($o, $key) => ['key' => $key] + $o)->values()->all(),
        ];
    }

    /** Enabled-but-no-keys is a server misconfiguration: refuse rather than skip the check (see TurnstileService). */
    private function turnstileUnavailable(): ?JsonResponse
    {
        return $this->turnstile->enabled() && $this->turnstile->misconfigured()
            ? response()->json(['message' => 'This page can\'t accept entries right now (spam protection isn\'t set up on this server).'], 503)
            : null;
    }

    private function turnstileRule(): array
    {
        return ['cf-turnstile-response' => $this->turnstile->enabled() ? 'required' : 'nullable'];
    }

    private function verifyTurnstile(Request $request, array $data): void
    {
        if ($this->turnstile->enabled()) {
            $this->turnstile->verify($data['cf-turnstile-response'], $request->ip());
        }
    }

    private function cookieToken(Request $request): ?string
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && strlen($token) >= 20 ? $token : null;
    }

    /** This browser's vote (by cookie), or the signed-in person's from another device. */
    private function existingVote(Request $request, string $slug, ?string $token = null, bool $lock = false): ?DesignPollVote
    {
        $token ??= $this->cookieToken($request);
        $personId = Auth::id();
        if (! $token && ! $personId) {
            return null;
        }

        $query = DesignPollVote::where('poll_slug', $slug)->where(function ($q) use ($token, $personId) {
            if ($token) {
                $q->orWhere('voter_token_hash', hash('sha256', $token));
            }
            if ($personId) {
                $q->orWhere('person_id', $personId);
            }
        });

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
