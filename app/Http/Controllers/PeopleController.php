<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\JsonResponse;

class PeopleController extends Controller
{
    /**
     * Display a listing of the people with their calculated name field.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $people = Person::all()->map(function ($person) {
            return [
                'id' => $person->id,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'email' => $person->email,
                'organization' => $person->organization,
                'phone' => $person->phone,
                'address' => $person->address,
                'city' => $person->city,
                'state' => $person->state,
                'zip' => $person->zip,
                'type' => $person->type,
                'comments' => $person->comments,
                'created_at' => $person->created_at,
                'updated_at' => $person->updated_at,
                'name' => "{$person->last_name}, {$person->first_name} ({$person->email})",
                ];
        });
            
            return response()->json(['records' => $people]);
    }
}
