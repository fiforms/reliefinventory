<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Helpers\UPCGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemType extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'itemtypes';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'family',
        'variant',
        'status',
        'pick_sequence',
        'storage_class',
        'category_id',
        'unit_id',
        'name',
        'description',
        'active',
        // See Person::$fillable's source_system/source_ref comment.
        'source_system',
        'source_ref',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'active' => 'boolean',
        'pick_sequence' => 'integer',
    ];

    /**
     * The attributes that should be appended to array/JSON output.
     *
     * @var array
     */
    protected $appends = ['display_number'];

    /**
     * Relationships
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'itemtype_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Families where "00" is a real, specific, chosen variant — on equal
     * footing with every other numbered variant — rather than a stand-in
     * for "no further specification needed." Per HANDOFF-item-numbering.md:
     * "One deliberate exception: 404-00 = newborn diapers... everywhere
     * else -00 is unused/reserved." A family-level fact, not a per-row
     * flag — every "00" row outside this list genuinely is the family's
     * default/standard item and displays bare.
     */
    private const VARIANT_ZERO_IS_MEANINGFUL = ['0404'];

    /**
     * Human-facing display form. Family is always stored as a full 4-digit
     * zero-padded string ("0042"), but only the reserved large-item blocks
     * (appliances/building/furniture/bible, numeric value >= 1000) are
     * actually meant to be seen as 4 digits — every other family drops its
     * single leading zero to match the memorized 3-digit form ("042", not
     * "0042"). The "-00" variant suffix is hidden for the standard/default
     * item of a family, where one exists, and shown for every other
     * variant — including "00" itself for the families in
     * VARIANT_ZERO_IS_MEANINGFUL, where it isn't a default at all. Per
     * HANDOFF-item-numbering.md and the numbering design session that
     * followed it.
     */
    public function getDisplayNumberAttribute(): ?string
    {
        if (! $this->family) {
            return null;
        }

        $displayFamily = ((int) $this->family) < 1000 ? substr($this->family, 1) : $this->family;

        $hideVariant = ! $this->variant
            || ($this->variant === '00' && ! in_array($this->family, self::VARIANT_ZERO_IS_MEANINGFUL, true));

        return $hideVariant ? $displayFamily : "{$displayFamily}-{$this->variant}";
    }

    /**
     * Parse a fully-formed, canonical number into [family, variant] — the
     * exact-match fast path for a barcode scan or someone typing the full
     * zero-padded number. Accepts a hyphenated form ("42-01", "0042-01")
     * or the canonical undashed one, split by fixed position: family is
     * always the first 4 characters, variant (if present) the remaining 2.
     *
     * This does NOT resolve shorthand (a family typed without its leading
     * zero, undashed) — "4201" can't be safely told apart from a real
     * 4-digit family here, since real 4-digit families exist (1100, 2530).
     * Shorthand entry belongs in a search-as-you-type UI that shows the
     * real matching candidates by name for the user to confirm, not a
     * parser that guesses — see the numbering design session notes.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function parseNumber(string $input): array
    {
        $input = trim($input);

        if (str_contains($input, '-')) {
            [$family, $variant] = array_pad(explode('-', $input, 2), 2, null);

            return [
                str_pad($family, 4, '0', STR_PAD_LEFT),
                $variant !== null ? str_pad($variant, 2, '0', STR_PAD_LEFT) : null,
            ];
        }

        if (! ctype_digit($input)) {
            return [$input, null];
        }

        return match (strlen($input)) {
            4 => [$input, null],
            6 => [substr($input, 0, 4), substr($input, 4, 2)],
            default => [$input, null],
        };
    }

    /**
     * Ensure that all item types have exactly one generic item
     *
     * @return array Count of updated items.
     */
    public static function refreshGenericItems()
    {
        $itemTypes = self::all();
        $createdCount = 0;
        $updatedCount = 0;

        foreach ($itemTypes as $itemType) {
            // Sort-hold item types quick-added with no number assigned yet
            // have no family/variant to generate a UPC from until reviewed.
            if (! $itemType->family || ! $itemType->variant) {
                continue;
            }

            $generatedUPC = UPCGenerator::makeUPC($itemType->family, $itemType->variant);

            // Check if a generic item exists
            $item = Item::where('itemtype_id', $itemType->id)
                ->where(function ($query) use ($generatedUPC) {
                    $query->where('pluscode', '0000')
                        ->orWhere('upc', $generatedUPC);
                })->first();

            // Define the correct values
            $correctValues = [
                'packagetypes_id' => 1, // Assuming default package type ID
                'pluscode' => '0000',
                'size' => 1.0,
                'case_qty' => 1,
                'active' => 1,
                'description' => $itemType->name.' GENERIC ITEM',
                'upc' => $generatedUPC,
            ];

            if ($item) {
                // If the item exists, check if it needs updating
                $needsUpdate = false;
                foreach ($correctValues as $key => $value) {
                    if ($value != $item->$key) {
                        $needsUpdate = true;
                        break;
                    }
                }

                if ($needsUpdate) {
                    $item->update($correctValues);
                    $updatedCount++;
                }
            } else {
                // If the item doesn't exist, create it
                Item::create(array_merge(['itemtype_id' => $itemType->id], $correctValues));
                $createdCount++;
            }
        }

        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
        ];
    }
}
