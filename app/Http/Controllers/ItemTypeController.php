<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Helpers\UPCGenerator;
use App\Models\ItemType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemTypeController extends Controller
{
    /**
     * family/variant are the family-variant numbering scheme
     * (see HANDOFF-item-numbering.md) — stored as separate fields, never
     * concatenated, per that document's non-negotiable rule. Both are
     * nullable: a sorter quick-adding a never-seen item type from the
     * sorting floor doesn't invent a number — it's created with no
     * family/variant, status forced to sort_hold, and picked up later by
     * supervisor review (the number wizard, not yet built) to assign a
     * real number.
     */
    private static function validation($id = null): array
    {
        return [
            // Accept 1-4 digits here (admin typing "42" is fine) — store()
            // zero-pads to the canonical 4-digit width before saving.
            'family' => ['nullable', 'regex:/^\d{1,4}$/'],
            'variant' => ['nullable', 'regex:/^\d{1,2}$/'],
            'status' => ['nullable', Rule::in(['orderable', 'sort_hold', 'retired'])],
            'pick_sequence' => ['nullable', 'integer', 'min:1', 'max:9'],
            'storage_class' => ['nullable', Rule::in(['F', 'L', 'K', 'A', 'N'])],
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'active' => 'required|boolean',
            'items' => 'nullable|array',
            'items.*.packagetypes_id' => 'required|exists:packagetypes,id',
            'items.*.pluscode' => 'required|string|max:4',
            'items.*.size' => 'nullable|numeric',
            'items.*.case_qty' => 'nullable|integer',
            'items.*.active' => 'required|boolean',
            'items.*.description' => 'nullable|string',
            'items.*.upc' => 'nullable|string|max:50',
        ];
    }

    /**
     * (family, variant) must be unique together — NULL variant participates
     * (one bare item per family), and a pending (NULL, NULL) row from
     * quick-add never collides since it isn't a real number yet.
     */
    private static function assertFamilyVariantAvailable(?string $family, ?string $variant, ?int $excludingId = null): ?string
    {
        if (! $family) {
            return null;
        }

        $query = ItemType::where('family', $family)->where('variant', $variant);
        if ($excludingId) {
            $query->where('id', '!=', $excludingId);
        }

        return $query->exists() ? 'That family/variant number is already in use.' : null;
    }

    // Display a listing of item types
    public function index(string $mod = '')
    {
        $with = ['category', 'unit', 'items.packagetype'];
        if ($mod == 'noitems') {
            $with = ['category', 'unit'];
        }
        $itemTypes = ItemType::with($with)->get();

        $templates = [
            '_default' => [
                'family' => null,
                'variant' => null,
                'status' => 'orderable',
                'pick_sequence' => null,
                'storage_class' => null,
                'category_id' => null,
                'unit_id' => null,
                'name' => '',
                'description' => '',
                'active' => true,
                'items' => [],
            ],
            'items' => [
                'packagetype_id' => null,
                'pluscode' => '0000',
                'size' => null,
                'case_qty' => null,
                'active' => true,
                'description' => '',
                'upc' => null,
            ],
        ];

        return response()->json([
            'records' => $itemTypes,
            'templates' => $templates,
        ]);
    }

    /**
     * Zero-pad family to 4 digits and variant to 2, so an admin can type
     * "42" instead of "0042" — this is the slow, careful, wizard-adjacent
     * path, not the fast order-entry/scan path, so it's fine to be forgiving
     * here. A family with no variant given defaults to "00" (the standard
     * item of that family), matching every other row created this way.
     */
    private static function padFamilyVariant(array $data): array
    {
        if (! empty($data['family'])) {
            $data['family'] = str_pad($data['family'], 4, '0', STR_PAD_LEFT);
            $data['variant'] = str_pad($data['variant'] ?? '00', 2, '0', STR_PAD_LEFT);
        }

        return $data;
    }

    // Store a newly created item type in storage
    public function store(Request $request)
    {
        $data = self::padFamilyVariant($request->validate(self::validation()));

        if (empty($data['family'])) {
            // Quick-add with no number assigned yet: valid at the sorting
            // table, held out of order forms until a supervisor assigns a
            // real family/variant.
            $data['family'] = null;
            $data['variant'] = null;
            $data['status'] = 'sort_hold';
        } else {
            $data['status'] = $data['status'] ?? 'orderable';
            if ($conflict = self::assertFamilyVariantAvailable($data['family'], $data['variant'] ?? null)) {
                return response()->json(['errors' => ['family' => $conflict]], 422);
            }
        }

        $itemType = ItemType::create($data);

        $genericItem = [
            'packagetypes_id' => 1, // Assuming default package type ID
            'pluscode' => '0000',
            'size' => 1.0,
            'case_qty' => 1,
            'active' => 1,
            'description' => $itemType->name.' GENERIC ITEM',
        ];

        // No UPC to generate until this item type has a real family.
        if ($itemType->family) {
            $genericItem['upc'] = UPCGenerator::makeUPC($itemType->family, $itemType->variant);
        }

        $itemType->items()->create($genericItem);

        return response()->json($itemType, 201);
    }

    // Update the specified item type in storage
    public function update(Request $request, $id)
    {
        $itemType = ItemType::findOrFail($id);
        $data = self::padFamilyVariant($request->validate(self::validation($id)));

        if (! empty($data['family']) && $conflict = self::assertFamilyVariantAvailable($data['family'], $data['variant'] ?? null, (int) $id)) {
            return response()->json(['errors' => ['family' => $conflict]], 422);
        }

        $itemType->update($data);

        // Retrieve current item IDs from the database
        $existingItemIds = $itemType->items()->pluck('id')->toArray();

        // Extract incoming item IDs
        $updatedItemIds = collect($request->input('items', []))
            ->pluck('id')
            ->filter() // Remove nulls (new records won't have IDs)
            ->toArray();

        // Identify items that need to be deleted
        $deletedItemIds = array_diff($existingItemIds, $updatedItemIds);

        // Delete removed items
        if (! empty($deletedItemIds)) {
            $itemType->items()->whereIn('id', $deletedItemIds)->delete();
        }

        // Handle new and updated items
        foreach ($request->input('items', []) as $itemData) {
            if (! empty($itemData['id'])) {
                // Update existing item
                $existingItem = $itemType->items()->find($itemData['id']);
                if ($existingItem) {
                    $existingItem->update($itemData);
                }
            } else {
                // Create a new item
                $itemType->items()->create($itemData);
            }
        }

        return response()->json($itemType->load('items'));
    }

    // Remove the specified item type from storage
    public function destroy($id)
    {
        $itemType = ItemType::findOrFail($id);
        $itemType->delete();

        return response()->json(['message' => 'Item Type deleted successfully']);
    }
}
