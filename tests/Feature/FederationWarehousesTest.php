<?php

use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Models\KioskLocation;
use App\Services\Federation\PeerUrl;
use App\Services\Federation\RequestSigner;
use App\Services\Warehouses\WarehouseContext;
use Tests\SecondWarehouse;

// MULTI_WAREHOUSE.md §4: machines address a warehouse by URL (`/w/<id>`), and nothing a client holds for one
// warehouse works at another — even a client that kept one key across both pairings.

beforeEach(function () {
    $this->other = SecondWarehouse::make($this);
    $this->host = parse_url(config('app.url'), PHP_URL_HOST).(($p = parse_url(config('app.url'), PHP_URL_PORT)) ? ':'.$p : '');
    // One device key, paired with both warehouses (the Launcher and print client keep their key across pairings).
    $this->peer = fedPeer('rstation');
    $enroll = fn () => fedEnroll($this->peer, ['print.share'], KioskLocation::create(['name' => 'Site'])->id);
    $enroll();
    app(WarehouseContext::class)->run($this->other, $enroll);
});

afterEach(fn () => app(WarehouseContext::class)->switchTo(Warehouse::default()));

test('a credential is for one warehouse: the prefix is part of its audience', function () {
    $prefix = '/w/'.$this->other->id;
    $mint = fn (string $audience) => RequestSigner::mintBearer($this->peer['uuid'], $audience, time(), time() + 3600, $this->peer['secret']);

    $this->withToken($mint($this->host.$prefix))->postJson($prefix.'/api/devices/heartbeat', [])->assertOk();
    $this->withToken($mint($this->host))->postJson('/api/devices/heartbeat', [])->assertOk();

    // Replayed at the other warehouse's address: refused both ways.
    $this->withToken($mint($this->host))->postJson($prefix.'/api/devices/heartbeat', [])->assertUnauthorized();
    $this->withToken($mint($this->host.$prefix))->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
});

test('a signature covers the prefix: one made for the unprefixed path fails at the prefixed one', function () {
    $prefixed = '/w/'.$this->other->id.'/api/federation/v1/grants';

    fedCall($this, $this->peer, 'GET', $prefixed)->assertOk();
    fedCall($this, $this->peer, 'GET', $prefixed, signedUri: '/api/federation/v1/grants')->assertUnauthorized();
});

test('the well-known document names the warehouse and says whether its address is shared', function () {
    $this->getJson('/w/'.$this->other->id.'/.well-known/ri-instance.json')
        ->assertJson(['warehouse' => $this->other->id, 'shared_host' => true]);
    $this->getJson('/.well-known/ri-instance.json')
        ->assertJson(['warehouse' => Warehouse::default()->id, 'shared_host' => true]);

    WarehouseDomain::create(['hostname' => 'other.example.test', 'warehouse_id' => $this->other->id, 'is_primary' => true]);
    $this->getJson('http://other.example.test/.well-known/ri-instance.json')
        ->assertJson(['warehouse' => $this->other->id, 'shared_host' => false]);
});

test('a new warehouse identity records the address machines reach it at', function () {
    config(['app.url' => 'https://relief.example.org']);
    $context = app(WarehouseContext::class);

    expect($context->run($this->other, fn () => $this->other->machineUrl()))->toBe('https://relief.example.org/w/'.$this->other->id)
        ->and(Warehouse::default()->machineUrl())->toBe('https://relief.example.org');
});

test('a peer address keeps its warehouse prefix and nothing else', function () {
    expect(PeerUrl::normalize('https://Peer.example.org/w/k7q2x9md/anything'))->toBe('https://peer.example.org/w/K7Q2X9MD')
        ->and(PeerUrl::normalize('https://peer.example.org:8443/some/path'))->toBe('https://peer.example.org:8443')
        ->and(PeerUrl::origin('https://peer.example.org/w/K7Q2X9MD'))->toBe('https://peer.example.org')
        ->and(PeerUrl::basePath('https://peer.example.org/w/K7Q2X9MD'))->toBe('/w/K7Q2X9MD')
        ->and(PeerUrl::basePath('https://peer.example.org'))->toBe('');
});
