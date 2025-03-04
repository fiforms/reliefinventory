<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\PalletStatus;
use App\Models\Pallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PalletStatusController extends Controller
{
    private const validation = [
        'pallet_id' => 'required|exists:pallets,id',
        'location_id' => 'required|exists:locations,id',
        'status' => 'required|in:created,wrapped,moved,unwrapped,archived',
        'notes' => 'nullable|string',
    ];
    
    /**
     * Retrieve all pallet status records.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Retrieve all pallet status records with their related pallet and location
        $palletStatuses = PalletStatus::with(['pallet', 'location'])
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json([
            'records' => $palletStatuses,
            'templates' => [
                '_default' => [
                    'pallet_id' => null,
                    'location_id' => null,
                    'status' => 'moved', // Default status for pallet movement
                    'notes' => null,
                    'pallet' => null,
                    'location' => null,
                ],
            ]
        ]);
    }
    
    /**
     * Get available status options for pallets.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statuses()
    {
        $statuses = [
            ['id' => 'created', 'name' => 'Created'],
            ['id' => 'wrapped', 'name' => 'Wrapped'],
            ['id' => 'moved', 'name' => 'Moved'],
            ['id' => 'unwrapped', 'name' => 'Unwrapped'],
            ['id' => 'archived', 'name' => 'Archived'],
        ];
        
        return response()->json([
            'records' => $statuses
        ]);
    }
     
    /**
     * Store a new pallet status record.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        
        $palletStatus = PalletStatus::create($data);
        
        // Update the pallet's last location and status
        $pallet = Pallet::findOrFail($data['pallet_id']);
        $pallet->update([
            'last_location_id' => $data['location_id'],
            'last_status' => $data['status']
        ]);
        
        return response()->json([
            'message' => 'Pallet status created successfully.',
            'record' => $palletStatus
        ], 201);
    }
    
    /**
     * Update an existing pallet status record.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate(self::validation);
        
        $palletStatus = PalletStatus::findOrFail($id);
        $palletStatus->update($data);
        
        // Also update the pallet's last location and status
        $pallet = Pallet::findOrFail($data['pallet_id']);
        $pallet->update([
            'last_location_id' => $data['location_id'],
            'last_status' => $data['status']
        ]);
        
        return response()->json([
            'message' => 'Pallet status updated successfully.'
        ], 200);
    }
    
    /**
     * Delete a pallet status record.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $palletStatus = PalletStatus::findOrFail($id);
            $palletStatus->delete();
            
            return response()->json([
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting pallet status: ' . $e->getMessage()
            ], 500);
        }
    }
}