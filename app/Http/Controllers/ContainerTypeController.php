<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\ContainerType;
use Illuminate\Http\Request;

class ContainerTypeController extends Controller
{
    public function index()
    {
        $containerTypes = ContainerType::orderBy('name')->get();

        return response()->json([
            'records' => $containerTypes,
            'templates' => ['_default' => ['name' => '']],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255|unique:container_types,name']);

        return response()->json(['record' => ContainerType::create($data)], 201);
    }

    public function update(Request $request, $id)
    {
        $containerType = ContainerType::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:255|unique:container_types,name,'.$id]);
        $containerType->update($data);

        return response()->json(['record' => $containerType]);
    }

    public function destroy($id)
    {
        ContainerType::findOrFail($id)->delete();

        return response()->json(['message' => 'Container type deleted successfully.']);
    }
}
