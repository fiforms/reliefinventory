<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One hosted warehouse: a complete dataset in its own schema (MULTI_WAREHOUSE.md). Lives in the control
 * schema. Not to be confused with App\Models\Warehouse, the physical building that locations belong to
 * inside a warehouse's own schema.
 */
class Warehouse extends Model
{
    protected $connection = 'control';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUSES = ['provisioning', 'awaiting_setup', 'active', 'maintenance', 'restoring', 'upgrading', 'held', 'suspended', 'archived'];

    /** Crockford base32 without 0 and 1 (Crockford already drops I, L, O and U). */
    private const ID_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    protected $fillable = ['schema_name', 'display_name', 'admin_email', 'status', 'listed', 'is_training', 'notes'];

    protected $casts = [
        'is_default' => 'boolean',
        'listed' => 'boolean',
        'is_training' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $warehouse) {
            $warehouse->id ??= self::newId();
            $warehouse->uuid ??= (string) Str::uuid();
        });
    }

    public static function newId(): string
    {
        do {
            $id = '';
            for ($i = 0; $i < 8; $i++) {
                $id .= self::ID_ALPHABET[random_int(0, strlen(self::ID_ALPHABET) - 1)];
            }
        } while (self::whereKey($id)->exists());

        return $id;
    }

    /** The warehouse unprefixed machine URLs and a single-warehouse instance use (§4). */
    public static function default(): ?self
    {
        return self::where('is_default', true)->first();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(WarehouseDomain::class);
    }

    /**
     * The database connection settings for this warehouse: the instance's base connection pointed at its
     * schema. The base is passed in rather than read from config, because config's default connection is
     * the one WarehouseContext re-points.
     */
    public function connectionConfig(array $base): array
    {
        return ['database' => $this->schema_name] + $base;
    }
}
