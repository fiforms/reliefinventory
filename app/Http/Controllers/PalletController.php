<?php

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Models\PalletStatus;
use Illuminate\Http\Request;

class PalletController extends Controller
{
    
    
    private const validation = [
        'datepacked' => 'required|date',
        'last_location_id' => 'nullable|exists:locations,id',
        'last_status' => 'required|in:created,wrapped,moved,unwrapped,archived',
        ];
    /**
     * Display a listing of the pallets, optionally filtered by last_status.
     */
    public function index(String $lastStatus = null)
    {
        $query = Pallet::query();
        
        if ($lastStatus) {
            $query->where('last_status', $lastStatus);
        }
        
        $pallets = $query->with('statuses.location')->orderBy('id','desc')->get();
        
        return response()->json([
            'records' => $pallets,
            'templates' => [
                '_default' => [
                    'id' => null,
                    'datepacked' => now()->toDateString(),
                    'last_location_id' => null,
                    'last_status' => 'created',
                    'statuses' => [],
                ],
            ],
        ]);
    }
    
    /**
     * Create a new pallet
     */
    public function store(Request $request)
    {
        // Plain auto-increment pallet IDs. A prior "unique last two digits"
        // scheme lived here; it compared full IDs (so it never actually
        // produced unique-last-two-digit results) and raced under concurrent
        // creates. Renumbering was never needed at 1700+ pallet scale.
        $pallet = Pallet::create(['last_status' => 'created', 'datepacked' => now()->toDateString()]);
        
        // Log status change in palletstatus
        PalletStatus::create([
            'pallet_id' => $pallet->id,
            'status' => 'created',
        ]);
        
        $pallet->statuses = [];
        
        return response()->json([
            'status' => 'Pallet created successfully.',
            'record' => $pallet,
        ], 201);
    }
    
    
    /**
     * Update an existing pallet.
     */
    public function update(Request $request, $id)
    {
        $pallet = Pallet::findOrFail($id);
        
        $validatedData = $request->validate(self::validation);
        $pallet->update($validatedData);
        
        // Log status change in palletstatus
        PalletStatus::create([
            'pallet_id' => $pallet->id,
            'location_id' => $validatedData['last_location_id'],
            'status' => $validatedData['last_status'],
        ]);
        
        return response()->json([
            'message' => 'Pallet updated successfully.',
        ], 200);
    }
    
    /**
     * Remove the specified pallet from storage.
     */
    public function destroy($id)
    {
        $pallet = Pallet::findOrFail($id);
        $pallet->delete();
        
        return response()->json([
            'message' => 'Pallet deleted successfully.',
        ], 200);
    }
}
