<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Person;
use App\Models\PeopleRoles;
use Illuminate\Support\Facades\Auth;


class PeopleController extends Controller
{
    // Validation rules for people
    private const VALIDATION_RULES = [
        'first_name' => 'required|string|max:255',
        'last_name' => 'required|string|max:255',
        'organization' => 'nullable|string|max:255',
        'phone' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255|unique:people,email',
        'address' => 'nullable|string',
        'city' => 'nullable|string|max:255',
        'state' => 'nullable|string|max:2',
        'zip' => 'nullable|string|max:10',
        'comments' => 'nullable|string',
        'people_roles' => 'nullable|array',
        'people_roles.*.role_id' => 'required|exists:roles,id',
    ];

    /**
     * Retrieve all people (customers and donors).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $people = Person::with(['people_roles','roles'])->get();

        return response()->json([
            'records' => $people,
            'templates' => [
                '_default' => [
                    'first_name' => '',
                    'last_name' => '',
                    'organization' => '',
                    'phone' => '',
                    'email' => '',
                    'address' => '',
                    'city' => '',
                    'state' => '',
                    'zip' => '',
                    'comments' => '',
                    'people_roles' => [],
                ],
                'people_roles' => [
                  'role_id' => null,
                ],
            ]
        ]);
    }

    /**
     * Store a new person (customer or donor).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $user_level = $user->role_bitpack; 
        
        $data = $request->validate(self::VALIDATION_RULES);
        
        // Calculate role_bitpack
        $roleBitpack = $this->calculateRoleBitpack($data['roles'] ?? []);
        
        // Check if the new role bitpack contains a bit that is not in the current user's bitpack
        if (($roleBitpack & ~$user_level) !== 0) {
            return response()->json([
                'message' => 'You cannot assign a higher privilege level than your own.'
            ], 403);
        }
        
        // Create person with computed role_bitpack
        $person = Person::create(array_merge($data, ['role_bitpack' => $roleBitpack]));
        
        $id = $person->id;
        
        // Insert new roles
        if (!empty($data['people_roles'])) {
            $newRoles = array_map(fn($role) => [
                'person_id' => $id,
                'role_id' => $role['role_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $data['people_roles']);
            
            PeopleRoles::insert($newRoles);
        }
        return response()->json([
            'message' => 'Person added successfully.',
            'record' => $person
        ], 201);
    }

    /**
     * Update an existing person.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
     
        $user = Auth::user();
        $user_level = $user->role_bitpack;
        
        $person = Person::findOrFail($id);
        
        if (($person->role_bitpack & ~$user_level) !== 0) {
            return response()->json([
                'message' => 'You cannot modify information for a person with a higher privilege level than your own.'
            ], 403);
        }
        
        
        $rules = self::VALIDATION_RULES;
        $rules['email'] = 'nullable|email|max:255|unique:people,email,' . $id;
        
        $data = $request->validate($rules);
        
        // Calculate role_bitpack
        $roleBitpack = $this->calculateRoleBitpack($data['roles'] ?? []);
        
        // Check if the new role bitpack contains a bit that is not in the current user's bitpack
        if (($roleBitpack & ~$user_level) !== 0) {
            return response()->json([
                'message' => 'You cannot assign a higher privilege level than your own.'
            ], 403);
        }

        
        // Calculate role_bitpack
        $roleBitpack = $this->calculateRoleBitpack($data['people_roles'] ?? []);
        $data['role_bitpack'] = $roleBitpack;
        
       
        // Update person with computed role_bitpack
        $person->update($data);
        
        // Delete all existing roles for this person
        PeopleRoles::where('person_id', $id)->delete();
        
        // Insert new roles
        if (!empty($data['people_roles'])) {
            $newRoles = array_map(fn($role) => [
                'person_id' => $id,
                'role_id' => $role['role_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $data['people_roles']);
            
            PeopleRoles::insert($newRoles);
        }
        
        return response()->json([
            'message' => 'Person updated successfully.',
        ], 200);
    }

    /**
     * Delete a person.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $person = Person::findOrFail($id);
        $person->delete();

        return response()->json([
            'message' => 'Person deleted successfully.'
        ], 200);
    }
    
    
    private function calculateRoleBitpack(array $roles): int
    {
        $bitpack = 0;
        foreach ($roles as $role) {
            $bitpack += pow(2, $role['role_id']);
        }
        return $bitpack;
    }
}
