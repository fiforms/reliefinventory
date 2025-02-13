<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Person;

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
        'type' => 'required|in:customer,donor',
        'comments' => 'nullable|string',
    ];

    /**
     * Retrieve all people (customers and donors).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $people = Person::all();

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
                    'type' => 'customer',
                    'comments' => '',
                ]
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
        $validatedData = $request->validate(self::VALIDATION_RULES);
        
        $person = Person::create($validatedData);

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
        $person = Person::findOrFail($id);
        
        $rules = self::VALIDATION_RULES;
        $rules['email'] = 'nullable|email|max:255|unique:people,email,' . $id;

        $validatedData = $request->validate($rules);

        $person->update($validatedData);

        return response()->json([
            'message' => 'Person updated successfully.',
            'record' => $person
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
}
