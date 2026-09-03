<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskSuggestion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Index+store for kiosk_suggestions (Agency/Task type-ahead lists), keyed
 * by `kind`. `index` is reachable from both the kiosk itself (kiosk-access,
 * to populate the datalist) and the Kiosk Settings admin page
 * (manage-kiosk, to manage the lists) — see routes/web.php.
 */
class KioskSuggestionController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([KioskSuggestion::KIND_AGENCY, KioskSuggestion::KIND_TASK])],
        ]);

        return response()->json([
            'records' => KioskSuggestion::where('kind', $data['kind'])->orderBy('value')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([KioskSuggestion::KIND_AGENCY, KioskSuggestion::KIND_TASK])],
            'value' => [
                'required', 'string', 'max:255',
                Rule::unique('kiosk_suggestions')->where('kind', $request->input('kind')),
            ],
        ]);

        $suggestion = KioskSuggestion::create($data);

        return response()->json([
            'message' => 'Suggestion created successfully.',
            'record' => $suggestion,
        ], 201);
    }
}
