<?php

use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Services\TurnstileService;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
use App\Services\Warehouses\WarehouseDomains;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\SecondWarehouse;

// MULTI_WAREHOUSE.md §3/§13: mapping hostnames to warehouses, and the Turnstile hostname step.

beforeEach(function () {
    $this->other = SecondWarehouse::make($this, ['display_name' => 'Elsewhere'])->fresh();
    $this->domains = app(WarehouseDomains::class);
});

afterEach(fn () => app(WarehouseContext::class)->switchTo(Warehouse::default()));

test('a hostname is cleaned up, and what cannot be mapped is refused with a reason', function () {
    config(['app.url' => 'https://ri.example.org']);
    expect($this->domains->normalize('  Sub.Example.ORG '))->toBe('sub.example.org');

    foreach ([
        'https://sub.example.org' => 'just the hostname', 'sub.example.org:8080' => 'just the hostname', 'sub.example.org/path' => 'just the hostname', '' => 'just the hostname',
        '10.0.0.5' => 'IP address', 'localhost' => 'not a valid hostname', '-bad.example.org' => 'not a valid hostname', 'a..b.org' => 'not a valid hostname',
        'RI.example.org' => 'own address',
    ] as $input => $message) {
        expect(fn () => $this->domains->normalize((string) $input))->toThrow(InvalidArgumentException::class, $message);
    }
});

test('the first hostname of a warehouse is its primary one, which can change, and removing the primary promotes another', function () {
    $first = $this->domains->add($this->other, 'one.example.org');
    $second = $this->domains->add($this->other, 'Two.Example.org');
    expect($first->is_primary)->toBeTrue()->and($second->is_primary)->toBeFalse()->and($second->hostname)->toBe('two.example.org');

    $this->domains->makePrimary($second);
    expect($this->other->domains()->where('is_primary', true)->pluck('hostname')->all())->toBe(['two.example.org'])
        ->and($this->other->fresh()->publicRoot())->toContain('two.example.org');

    $this->domains->remove($this->other, 'two.example.org');
    expect($this->other->domains()->where('is_primary', true)->pluck('hostname')->all())->toBe(['one.example.org']);
    expect(fn () => $this->domains->remove($this->other, 'nowhere.example.org'))->toThrow(InvalidArgumentException::class);
});

test('a hostname reaches one warehouse only', function () {
    $this->domains->add($this->other, 'one.example.org');

    expect(fn () => $this->domains->add($this->other, 'ONE.example.org'))->toThrow(InvalidArgumentException::class, 'already mapped to this warehouse')
        ->and(fn () => $this->domains->add(Warehouse::default(), 'one.example.org'))->toThrow(InvalidArgumentException::class, 'another warehouse');
});

test('a mapped host reaches its warehouse and is locked to it', function () {
    $this->domains->add($this->other, 'one.example.org');

    $this->withHeader('X-RI-Warehouse', $this->other->id)->get('http://one.example.org/login?w='.Warehouse::default()->id)
        ->assertRedirect('http://one.example.org/login');
});

test('warehouse:domain adds, lists, makes primary and removes', function () {
    $id = $this->other->id;

    $this->artisan('warehouse:domain', ['action' => 'add', 'warehouse' => $id, 'hostname' => 'one.example.org'])->assertSuccessful();
    $this->artisan('warehouse:domain', ['action' => 'add', 'warehouse' => $id, 'hostname' => 'two.example.org', '--primary' => true])->assertSuccessful();
    $this->artisan('warehouse:domain', ['action' => 'add', 'warehouse' => $id, 'hostname' => '10.1.1.1'])->assertFailed();
    $this->artisan('warehouse:domain', ['action' => 'list'])->assertSuccessful()->expectsOutputToContain('two.example.org');
    $this->artisan('warehouse:domain', ['action' => 'primary', 'warehouse' => $id, 'hostname' => 'one.example.org'])->assertSuccessful();
    expect(WarehouseDomain::where('hostname', 'one.example.org')->value('is_primary'))->toBeTrue();
    $this->artisan('warehouse:domain', ['action' => 'remove', 'warehouse' => $id, 'hostname' => 'one.example.org'])->assertSuccessful();
    $this->artisan('warehouse:domain', ['action' => 'add', 'warehouse' => 'NOSUCHID', 'hostname' => 'x.example.org'])->assertFailed();
    $this->artisan('warehouse:domain', ['action' => 'frobnicate'])->assertFailed();
});

test('with Turnstile on, a host is flagged until a check succeeds on it', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'k', 'services.turnstile.secret_key' => 's']);
    $domain = $this->domains->add($this->other, 'one.example.org');
    expect($this->domains->turnstileNote($domain))->toContain('one.example.org', 'Cloudflare');

    // A check that passed on another host says nothing about this one.
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'one.example.org'])]);
    app()->instance('request', Request::create('http://elsewhere.example.org/register', 'POST'));
    app(TurnstileService::class)->verify('t', '1.2.3.4');
    expect($domain->fresh()->turnstile_confirmed_at)->toBeNull();

    app()->instance('request', Request::create('http://one.example.org/register', 'POST'));
    app(TurnstileService::class)->verify('t', '1.2.3.4');
    $domain = $domain->fresh();
    expect($domain->turnstile_confirmed_at)->not->toBeNull()->and($this->domains->turnstileNote($domain))->toBeNull();
});

test('with Turnstile off there is nothing to confirm', function () {
    config(['services.turnstile.enabled' => false]);

    expect($this->domains->turnstileNote($this->domains->add($this->other, 'one.example.org')))->toBeNull();
});

test('warehouse names are unique on the instance, whatever the case', function () {
    expect(fn () => app(WarehouseCreator::class)->create('elsewhere'))->toThrow(InvalidArgumentException::class, 'already a warehouse called')
        ->and(fn () => app(WarehouseCreator::class)->create('  '))->toThrow(InvalidArgumentException::class, 'needs a name');
});
