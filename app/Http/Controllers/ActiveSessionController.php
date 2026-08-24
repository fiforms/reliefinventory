<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\LoginHistory;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only "who's logged in" view for admins (permission: admin-system) —
 * lets someone about to push an update or restart a service check whether
 * anyone is actively working first. Backed entirely by the sessions table
 * (database session driver) plus TrackSessionActivity's last_url column —
 * no separate presence/heartbeat system. The frontend polls this every 60s;
 * see active-sessions-view memory / TrackSessionActivity for why that's
 * lightweight enough not to need push.
 */
class ActiveSessionController extends Controller
{
    public function index()
    {
        $activeSince = now()->subMinutes(15)->timestamp;

        $sessions = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $activeSince)
            ->orderByDesc('last_activity')
            ->get(['user_id', 'ip_address', 'last_url', 'last_activity']);

        $people = Person::with('roles')
            ->whereIn('id', $sessions->pluck('user_id')->unique())
            ->get()
            ->keyBy('id');

        $rows = $sessions->map(function ($session) use ($people) {
            $person = $people->get($session->user_id);

            return [
                'person_id' => $session->user_id,
                'name' => $person?->full_name ?? 'Unknown',
                'roles' => $person?->roles->pluck('name')->all() ?? [],
                'ip_address' => $session->ip_address,
                'last_url' => $session->last_url,
                'last_activity' => date('c', $session->last_activity),
            ];
        })->values();

        return response()->json([
            'sessions' => $rows,
            'active_window_minutes' => 15,
        ]);
    }

    /**
     * Recent login history — a permanent log (LoginHistory) distinct from
     * the sessions table above, which only reflects current presence and
     * is overwritten on every request.
     *
     * With no `person_id`, this is used for the summary view and returns
     * only the most recent login per person (see `latestPerPerson()`)
     * rather than a flat recent-N list — a single frequent user would
     * otherwise crowd everyone else out of the page. Pass `person_id` to
     * get that one person's full history instead, for the tap-to-expand
     * detail view.
     */
    public function history(Request $request)
    {
        $limit = min((int) $request->integer('limit', 50), 200);

        if ($request->filled('person_id')) {
            $rows = LoginHistory::with('person')
                ->where('person_id', $request->integer('person_id'))
                ->orderByDesc('logged_in_at')
                ->limit($limit)
                ->get()
                ->map($this->formatEntry(...));

            return response()->json(['history' => $rows]);
        }

        return response()->json(['history' => $this->latestPerPerson($limit)]);
    }

    /**
     * Most recent login per person, newest first — one row per person
     * regardless of how many times they've logged in.
     */
    private function latestPerPerson(int $limit)
    {
        $latestIds = LoginHistory::selectRaw('MAX(id) as id')
            ->groupBy('person_id')
            ->pluck('id');

        return LoginHistory::with('person')
            ->whereIn('id', $latestIds)
            ->orderByDesc('logged_in_at')
            ->limit($limit)
            ->get()
            ->map($this->formatEntry(...));
    }

    private function formatEntry(LoginHistory $entry): array
    {
        return [
            'id' => $entry->id,
            'person_id' => $entry->person_id,
            'name' => $entry->person?->full_name ?? 'Unknown',
            'method' => $entry->method,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'logged_in_at' => $entry->logged_in_at->toIso8601String(),
        ];
    }
}
