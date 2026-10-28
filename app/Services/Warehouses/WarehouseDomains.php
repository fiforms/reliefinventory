<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Services\TurnstileService;
use InvalidArgumentException;

/**
 * Mapping hostnames to warehouses (MULTI_WAREHOUSE.md §3). A mapped host reaches exactly one warehouse and ignores `?w=` and the
 * selector cookie, so it needs DNS and TLS pointed at this instance first (that is outside the app). The instance's own address
 * (APP_URL) can't be mapped: it is the shared address, and the default warehouse's machine URLs live on it.
 */
class WarehouseDomains
{
    /**
     * A bare, lower-case hostname, or an error saying what is wrong: no scheme, port or path, no IP address, a dot in it.
     *
     * @throws InvalidArgumentException
     */
    public function normalize(string $input): string
    {
        $host = strtolower(trim($input));
        if ($host === '' || preg_match('#[/:@\s]#', $host)) {
            throw new InvalidArgumentException('Give just the hostname (for example warehouse2.example.org): no http://, port or path.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('An IP address can\'t be mapped to one warehouse; use a hostname.');
        }
        if ($host === strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            throw new InvalidArgumentException('That is this instance\'s own address (APP_URL), shared by every warehouse that has no hostname of its own.');
        }
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            throw new InvalidArgumentException("'{$host}' is not a valid hostname.");
        }

        return $host;
    }

    /** Map $hostname to $warehouse. The warehouse's first hostname becomes its primary one (the address used in emails and links). */
    public function add(Warehouse $warehouse, string $hostname, bool $primary = false): WarehouseDomain
    {
        $host = $this->normalize($hostname);
        if ($existing = WarehouseDomain::where('hostname', $host)->first()) {
            throw new InvalidArgumentException($existing->warehouse_id === $warehouse->id
                ? "{$host} is already mapped to this warehouse."
                : "{$host} is already mapped to another warehouse.");
        }

        $domain = $warehouse->domains()->create(['hostname' => $host, 'is_primary' => false]);
        if ($primary || $warehouse->domains()->count() === 1) {
            $this->makePrimary($domain);
        }

        InstanceAuditLog::record('domain.added', $warehouse, ['hostname' => $host, 'primary' => $domain->fresh()->is_primary]);

        return $domain->fresh();
    }

    public function makePrimary(WarehouseDomain $domain): void
    {
        WarehouseDomain::where('warehouse_id', $domain->warehouse_id)->update(['is_primary' => false]);
        $domain->forceFill(['is_primary' => true])->save();
    }

    /** Unmap a hostname; if it was the primary one, another of the warehouse's hostnames takes over. */
    public function remove(Warehouse $warehouse, string $hostname): void
    {
        $domain = $warehouse->domains()->where('hostname', strtolower(trim($hostname)))->first()
            ?? throw new InvalidArgumentException("{$hostname} is not mapped to this warehouse.");
        $wasPrimary = $domain->is_primary;
        $domain->delete();
        InstanceAuditLog::record('domain.removed', $warehouse, ['hostname' => $domain->hostname]);

        if ($wasPrimary && $next = $warehouse->domains()->orderBy('id')->first()) {
            $this->makePrimary($next);
        }
    }

    /**
     * What to tell the instance admin about Turnstile for this host, or null when there is nothing to do (Turnstile is off, or the host
     * has already passed a check). Turnstile keys are instance-wide, but each Cloudflare widget lists the hostnames it accepts.
     */
    public function turnstileNote(WarehouseDomain $domain): ?string
    {
        $turnstile = app(TurnstileService::class);
        if (! $turnstile->enabled() || ! $turnstile->configured() || $domain->turnstile_confirmed_at) {
            return null;
        }

        return "Turnstile is on: add {$domain->hostname} to the hostnames of your Cloudflare Turnstile widget, or registration, /join and /vote will fail on it. "
            .'This note disappears once a Turnstile check has succeeded on that host.';
    }
}
