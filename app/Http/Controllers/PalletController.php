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

        // Find the maximum existing pallet ID
        $maxPalletId = Pallet::max('id') ?? 0;
        
        // Get all pallets where last_status = 'created'
        $createdPallets = Pallet::where('last_status', 'created')->pluck('id')->toArray();
        
        // If there are fewer than 100 pallets with last_status='created', assign a new unique last 2 digits
        if (count($createdPallets) < 100) {
            // Generate a unique ID with a unique last two digits
            $newPalletId = null;
            for ($i = 1; $i <= 100; $i++) {
                $candidateId = $maxPalletId + $i; // Ensure last two digits are unique
                if (!in_array($candidateId, $createdPallets)) {
                    $newPalletId = $candidateId;
                    break;
                }
            }
        } else {
            // If there are already 100 created pallets, fallback to normal auto-increment behavior
            $newPalletId = $maxPalletId + 1;
        }
        
        // Create pallet with the custom ID
        $pallet = Pallet::create(['id' => $newPalletId, 'last_status' => 'created', 'datepacked' => now()->toDateString()]);
        
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
