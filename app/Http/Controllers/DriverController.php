<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Rules\SecurePin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal index+store(+update) controller for the drivers lookup table — no
 * dedicated admin page. Managed inline via Receiving.vue's Driver
 * SearchSelect (allowcreate), the same quick-add pattern used for donors.
 * Gated by manage-receiving, since drivers are a sub-resource of intake
 * rather than their own concern. `update` exists only to link an existing
 * driver to a Person record after the fact (the "Use as Donor" action).
 * setPin (2026-08-27) is gated manage-orders instead, alongside Shipping —
 * it's how a driver gets access to the Driver Portal, not an intake concern.
 */
class DriverController extends Controller
{
    private const VALIDATION_RULES = [
        'name' => 'required|string|max:255',
        'phone' => 'nullable|string|max:50',
        'carrier' => 'nullable|string|max:255',
    ];

    public function index()
    {
        return response()->json([
            'records' => Driver::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $driver = Driver::create($data);

        return response()->json(['record' => $driver], 201);
    }

    public function update(Request $request, Driver $driver)
    {
        $data = $request->validate([
            ...self::VALIDATION_RULES,
            'person_id' => 'nullable|exists:people,id',
        ]);
        $driver->update($data);

        return response()->json(['record' => $driver]);
    }

    /**
     * Staff sets/resets a driver's Driver Portal PIN — same shape as
     * Auth\PinController's Person-side flow (digits:5 + SecurePin), just a
     * different owning model. pin_hash is deliberately not in $fillable;
     * set directly here only.
     */
    public function setPin(Request $request, Driver $driver)
    {
        $data = $request->validate(['pin' => ['required', 'digits:5', 'confirmed', new SecurePin]]);

        $driver->pin_hash = Hash::make($data['pin']);
        $driver->save();

        return response()->json(['success' => true]);
    }
}
