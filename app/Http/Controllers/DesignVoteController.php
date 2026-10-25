<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DesignPoll;
use App\Models\DesignPollIdea;
use App\Models\DesignPollReaction;
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
 * link, signed in or not, tries each option's live demo and says "I like this" or "Not for me" on any of them, each
 * with an optional why. An option left alone stays neutral; at least one reaction is required (disliking every
 * option is fine). One ballot per browser, kept by an HttpOnly cookie token (stored only as a hash); a signed-in
 * person's ballot also follows them to another device. Voting again replaces the earlier reactions while open. Results are shown on the vote page only once the
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
            'my_vote' => $vote ? [
                'voter_name' => $vote->voter_name,
                'reactions' => $vote->reactions()->whereIn('option_key', array_keys($poll['options']))
                    ->get(['option_key', 'reaction', 'comment'])->all(),
            ] : null,
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
            'reactions' => 'required|array|min:1',
            'reactions.*.option_key' => ['required', 'distinct', Rule::in(array_keys($poll['options']))],
            'reactions.*.reaction' => ['required', Rule::in(DesignPollReaction::REACTIONS)],
            'reactions.*.comment' => 'nullable|string|max:2000',
            'voter_name' => 'nullable|string|max:100',
        ] + $this->turnstileRule(), [
            'reactions.required' => 'Say whether you like at least one of the designs.',
            'reactions.min' => 'Say whether you like at least one of the designs.',
        ]);
        $this->verifyTurnstile($request, $data);

        $token = $this->cookieToken($request) ?? Str::random(40);
        $values = [
            'voter_name' => filled($data['voter_name'] ?? null) ? trim($data['voter_name']) : null,
            'ip_address' => $request->ip(),
        ];
        if (Auth::id()) {
            $values['person_id'] = Auth::id();
        }
        $reactions = array_map(fn ($r) => [
            'option_key' => $r['option_key'],
            'reaction' => $r['reaction'],
            'comment' => filled($r['comment'] ?? null) ? trim($r['comment']) : null,
        ], $data['reactions']);

        DB::transaction(function () use ($request, $slug, $token, $values, $reactions) {
            $vote = $this->existingVote($request, $slug, $token, lock: true);
            if ($vote) {
                $vote->update($values);
                $vote->touch(); // a re-vote with the same name still counts as an update
                $vote->reactions()->delete();
            } else {
                $vote = DesignPollVote::create($values + ['poll_slug' => $slug, 'voter_token_hash' => hash('sha256', $token)]);
            }
            $vote->reactions()->createMany($reactions);
        });

        return response()->json(['reactions' => $reactions], 201)
            ->cookie(self::COOKIE, $token, 60 * 24 * 365, '/', null, null, true, false, 'lax');
    }

    /**
     * "Have another idea?": free text about this question or anything else. Accepted whether or not the person
     * voted and even after voting has closed — the point is to catch an idea when it comes. Each one is kept. An
     * email is optional, unless "OK to contact me about this idea" is ticked.
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
            'email' => 'nullable|required_if_accepted:ok_to_contact|email|max:255',
            'ok_to_contact' => 'boolean',
        ] + $this->turnstileRule(), [
            'email.required_if_accepted' => 'Add your email so we can contact you, or untick the box.',
        ]);
        $this->verifyTurnstile($request, $data);

        DesignPollIdea::create([
            'poll_slug' => $slug,
            'idea' => trim($data['idea']),
            'person_id' => Auth::id(),
            'voter_name' => filled($data['voter_name'] ?? null) ? trim($data['voter_name']) : null,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
            'ok_to_contact' => (bool) ($data['ok_to_contact'] ?? false),
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
                'comments' => self::reactionRows($poll['slug'])->whereNotNull('design_poll_reactions.comment')
                    ->latest('design_poll_reactions.updated_at')->get(),
                'ideas' => DesignPollIdea::where('poll_slug', $poll['slug'])->latest()->get(['idea', 'voter_name', 'email', 'ok_to_contact', 'created_at']),
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
        $reactions = self::reactionRows($slug)->orderBy('design_poll_votes.id')->orderBy('design_poll_reactions.id')->get();
        $ideas = DesignPollIdea::where('poll_slug', $slug)->orderBy('created_at')->get();

        return response()->streamDownload(function () use ($poll, $reactions, $ideas) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Type', 'Voter', 'Sent', 'Design', 'Reaction', 'Name', 'Comment or idea', 'Email', 'OK to contact']);
            foreach ($reactions as $row) {
                fputcsv($out, [
                    'Vote',
                    $row->vote_id,
                    $row->updated_at,
                    $poll['options'][$row->option_key]['label'] ?? $row->option_key,
                    $row->reaction === 'like' ? 'Likes it' : 'Not for them',
                    $row->voter_name,
                    $row->comment,
                ]);
            }
            foreach ($ideas as $idea) {
                fputcsv($out, [
                    'Idea', null, $idea->created_at?->toDateTimeString(), null, null, $idea->voter_name, $idea->idea,
                    $idea->email, $idea->ok_to_contact ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, 'design-vote-'.$slug.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Likes and dislikes per option, every option listed: most liked first, then fewest dislikes. `voters` counts
     * ballots that reacted to at least one current option; `percent` is likes as a share of voters.
     *
     * @return array{voters: int, options: array<int, array{key: string, likes: int, dislikes: int, percent: int}>}
     */
    public static function results(string $slug): array
    {
        $rows = self::reactionRows($slug)->get();
        $voters = $rows->pluck('vote_id')->unique()->count();

        $options = collect(array_keys(DesignPolls::find($slug)['options']))
            ->map(function ($key) use ($rows, $voters) {
                $likes = $rows->where('option_key', $key)->where('reaction', 'like')->count();

                return [
                    'key' => $key,
                    'likes' => $likes,
                    'dislikes' => $rows->where('option_key', $key)->where('reaction', 'dislike')->count(),
                    'percent' => $voters ? (int) round(100 * $likes / $voters) : 0,
                ];
            })
            ->sort(fn ($a, $b) => [$b['likes'], $a['dislikes']] <=> [$a['likes'], $b['dislikes']])
            ->values()->all();

        return ['voters' => $voters, 'options' => $options];
    }

    /**
     * Every reaction on a poll's current options with its voter's name. Only the options the poll still has: a design
     * dropped after voting started stops counting.
     */
    private static function reactionRows(string $slug)
    {
        return DesignPollReaction::query()
            ->join('design_poll_votes', 'design_poll_votes.id', '=', 'design_poll_reactions.design_poll_vote_id')
            ->where('design_poll_votes.poll_slug', $slug)
            ->whereIn('design_poll_reactions.option_key', array_keys(DesignPolls::find($slug)['options']))
            ->select([
                'design_poll_votes.id as vote_id', 'design_poll_votes.voter_name',
                'design_poll_reactions.option_key', 'design_poll_reactions.reaction',
                'design_poll_reactions.comment', 'design_poll_reactions.updated_at',
            ]);
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
