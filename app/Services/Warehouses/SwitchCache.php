<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Facade;

/**
 * The default cache store follows the warehouse. The `database` store already does through the switched
 * connection, but the cache manager keeps store instances (each holding the connection it was made with), so they
 * are forgotten on every switch, along with the rate limiter built on one. Each warehouse also gets its own key
 * prefix, which keeps them apart on a store that isn't per schema (array, file). Only the default store: the
 * `accounts` store is instance-wide on purpose (login throttling per account, MULTI_WAREHOUSE.md §6).
 */
class SwitchCache implements SwitchTask
{
    private string $store;

    private string $basePrefix;

    public function __construct()
    {
        $this->store = (string) config('cache.default');
        $this->basePrefix = (string) (config("cache.stores.{$this->store}.prefix") ?? config('cache.prefix'));
    }

    public function makeCurrent(Warehouse $warehouse): void
    {
        $this->use($this->basePrefix.strtolower($warehouse->id).'_');
    }

    public function forgetCurrent(): void
    {
        $this->use($this->basePrefix);
    }

    private function use(string $prefix): void
    {
        if (config("cache.stores.{$this->store}.prefix") === $prefix) {
            return;
        }

        config(["cache.stores.{$this->store}.prefix" => $prefix]);
        app('cache')->forgetDriver($this->store);
        app()->forgetInstance('cache.store');
        // The rate limiter holds the old store; a new one gets the new store, and keeps the named limiters
        // (`RateLimiter::for()`, registered once at boot).
        if (app()->resolved(RateLimiter::class)) {
            $limiters = (fn () => $this->limiters)->call(app(RateLimiter::class));
            app()->forgetInstance(RateLimiter::class);
            Facade::clearResolvedInstance(RateLimiter::class);
            $limiter = app(RateLimiter::class);
            foreach ($limiters as $name => $callback) {
                $limiter->for($name, $callback);
            }
        }
    }
}
