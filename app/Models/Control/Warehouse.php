<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

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
     * schema, as its own database user once it has one (a password file next to its key, phase 3). The base is
     * passed in rather than read from config, because config's default connection is the one WarehouseContext
     * re-points.
     */
    public function connectionConfig(array $base): array
    {
        $password = $this->secretsPath('db_password');
        if (is_readable($password)) {
            $base = ['username' => 'ri_'.strtolower($this->id), 'password' => trim((string) file_get_contents($password))] + $base;
        }

        return ['database' => $this->schema_name] + $base;
    }

    /** A path inside the warehouse's own files folder, which is its `local` disk (MULTI_WAREHOUSE.md §11). */
    public function storagePath(string $path = ''): string
    {
        return rtrim(config('warehouses.storage_dir'), '/')."/{$this->id}/files".($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /** A path in the warehouse's secrets folder: outside storage, never in a backup (MULTI_WAREHOUSE.md §2). */
    public function secretsPath(string $file = ''): string
    {
        return rtrim(config('warehouses.secrets_dir'), '/')."/{$this->id}".($file === '' ? '' : "/{$file}");
    }

    public function hasKey(): bool
    {
        return is_readable($this->secretsPath('warehouse.key'));
    }

    /** WAREHOUSE_KEY (`base64:…`, like APP_KEY): what Crypt, encrypted columns and signed URLs use here (§5). */
    public function key(): string
    {
        $key = $this->hasKey() ? trim((string) file_get_contents($this->secretsPath('warehouse.key'))) : '';
        if ($key === '') {
            throw new RuntimeException("Warehouse {$this->id} has no key file. Run `php artisan instance:migrate`.");
        }

        return $key;
    }

    /** Write the key file once; an existing key is never replaced (it is what this warehouse's data needs). */
    public function writeKey(string $key): void
    {
        if ($this->hasKey()) {
            return;
        }

        $dir = $this->secretsPath();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }
        $tmp = $dir.'/.warehouse.key.'.getmypid();
        $old = umask(077);
        try {
            file_put_contents($tmp, $key) !== false && rename($tmp, $this->secretsPath('warehouse.key'))
                || throw new RuntimeException("Could not write the key for warehouse {$this->id}.");
        } finally {
            umask($old);
        }
    }
}
