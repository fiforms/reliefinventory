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
     */
    public function history(Request $request)
    {
        $limit = min((int) $request->integer('limit', 50), 200);

        $rows = LoginHistory::with('person')
            ->orderByDesc('logged_in_at')
            ->limit($limit)
            ->get()
            ->map(fn (LoginHistory $entry) => [
                'id' => $entry->id,
                'person_id' => $entry->person_id,
                'name' => $entry->person?->full_name ?? 'Unknown',
                'method' => $entry->method,
                'ip_address' => $entry->ip_address,
                'logged_in_at' => $entry->logged_in_at->toIso8601String(),
            ]);

        return response()->json(['history' => $rows]);
    }
}
