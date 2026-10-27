<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use App\Services\Warehouses\WarehouseContext;
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

    /** The warehouse this request, job or command works in. */
    public static function current(): self
    {
        return app(WarehouseContext::class)->current();
    }

    /**
     * A broadcast channel name inside this warehouse (`w.<id>.print-queue`): with one Reverb for every warehouse,
     * an unprefixed name would carry one warehouse's print queue to another's browsers (MULTI_WAREHOUSE.md §9).
     */
    public function channel(string $name): string
    {
        return "w.{$this->id}.{$name}";
    }

    /** An active warehouse by id (any case), or null — whether it doesn't exist or isn't active, alike. */
    public static function findActive(string $id): ?self
    {
        if (! preg_match('/^[A-Za-z0-9]{8}$/', $id)) {
            return null;
        }

        return self::whereKey(strtoupper($id))->where('status', 'active')->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
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

    /**
     * The address people reach this warehouse at, for links that leave the browser (emails) and for anything built
     * outside a request: its primary hostname (with APP_URL's scheme), else APP_URL. Never the request's Host
     * header, which the sender of a request chooses (MULTI_WAREHOUSE.md §3).
     */
    public function publicRoot(): string
    {
        $app = rtrim((string) config('app.url'), '/');
        $domain = $this->domains()->where('is_primary', true)->value('hostname');

        return $domain ? (parse_url($app, PHP_URL_SCHEME) ?: 'https').'://'.$domain : $app;
    }

    /**
     * The address machines (devices, peers, launchers) use for this warehouse (§4): its own hostname, or the
     * instance's address — plus `/w/<id>` for any warehouse but the default one, which unprefixed URLs reach.
     */
    public function machineUrl(): string
    {
        $root = $this->publicRoot();
        $ownHost = $this->domains()->where('is_primary', true)->exists();

        return $ownHost || $this->is_default ? $root : "{$root}/w/{$this->id}";
    }

    /** Whether $host reaches this warehouse by itself (a mapped hostname), rather than being shared with others. */
    public function ownsHost(string $host): bool
    {
        return $this->domains()->where('hostname', strtolower($host))->exists();
    }

    /** An absolute link into this warehouse for an email: publicRoot() plus `w=` when the address needs it. */
    public function publicUrl(string $path = '/'): string
    {
        return $this->withSelector($this->publicRoot().'/'.ltrim($path, '/'));
    }

    /**
     * $url with `w=<id>` added when its address alone doesn't reach this warehouse: there are several warehouses
     * and the host isn't one of this warehouse's own. With one warehouse, links stay as they always were.
     */
    public function withSelector(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! app(WarehouseContext::class)->multiple() || ($host !== '' && $this->domains()->where('hostname', $host)->exists())) {
            return $url;
        }
        [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);

        return $url.(str_contains($url, '?') ? '&' : '?').'w='.$this->id.($fragment !== null ? "#{$fragment}" : '');
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
