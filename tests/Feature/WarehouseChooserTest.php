<?php

use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\RateLimiter;
use Tests\SecondWarehouse;

// MULTI_WAREHOUSE.md §3: the chooser on the login page, and reaching a warehouse by typing its code.

beforeEach(function () {
    $this->default = Warehouse::default();
    $this->other = SecondWarehouse::make($this, ['display_name' => 'Elsewhere'])->fresh();
    $this->login = fn () => $this->withHeader('X-RI-Warehouse', $this->default->id);
});

afterEach(fn () => app(WarehouseContext::class)->switchTo(Warehouse::default()));

test('the login page names the warehouse and lists the listed, open ones to choose from', function () {
    Warehouse::create(['schema_name' => 'test_reliefinventory_quiet', 'display_name' => 'Quiet', 'listed' => false]);
    Warehouse::create(['schema_name' => 'test_reliefinventory_closed', 'display_name' => 'Closed', 'status' => 'suspended']);

    ($this->login)()->get('/login')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Auth/Login')
        ->where('warehouse.name', $this->default->display_name)->where('warehouse.multiple', true)
        ->where('chooser.options', fn ($options) => collect($options)->pluck('name')->sort()->values()->all() === collect([$this->default->display_name, 'Elsewhere'])->sort()->values()->all()));
});

test('with a single warehouse there is no chooser', function () {
    $this->other->forceFill(['status' => 'archived'])->save();

    ($this->login)()->get('/login')->assertInertia(fn ($page) => $page->where('chooser', null));
});

test('a mapped hostname has no chooser: it is locked to its warehouse', function () {
    WarehouseDomain::create(['hostname' => 'one.example.org', 'warehouse_id' => $this->other->id, 'is_primary' => true]);

    $this->withHeader('X-RI-Warehouse', $this->other->id)->get('http://one.example.org/login')->assertOk()
        ->assertInertia(fn ($page) => $page->where('chooser', null)->where('warehouse.name', 'Elsewhere'));
    $this->withHeader('X-RI-Warehouse', $this->other->id)->post('http://one.example.org/choose-warehouse', ['code' => $this->default->id])->assertSessionHasErrors('code');
});

test('picking a warehouse from the list goes through ?w= and sets the cookie', function () {
    ($this->login)()->get('/login?w='.$this->other->id)->assertRedirect('/login')->assertCookie('ri_warehouse', $this->other->id, false);
});

test('an unlisted warehouse is reached by typing its code, in any case or spacing', function () {
    $this->other->forceFill(['listed' => false])->save();

    ($this->login)()->post('/choose-warehouse', ['code' => ' '.strtolower($this->other->id).' '])->assertRedirect('/login?w='.$this->other->id);
});

test('a code that names nothing open is answered plainly, and typing codes is rate-limited', function () {
    RateLimiter::clear('choose');
    $this->other->forceFill(['status' => 'suspended'])->save();

    foreach (['ZZZZZZZZ', $this->other->id, 'not a code'] as $code) {
        ($this->login)()->post('/choose-warehouse', ['code' => $code])->assertSessionHasErrors(['code' => 'No warehouse matches that code.']);
    }

    foreach (range(1, 12) as $i) {
        $last = ($this->login)()->post('/choose-warehouse', ['code' => 'ZZZZZZZZ']);
    }
    $last->assertStatus(429);
});

test('a warehouse awaiting setup is reached by its code and lands on its setup screen', function () {
    $this->other->forceFill(['status' => 'awaiting_setup'])->save();

    ($this->login)()->post('/choose-warehouse', ['code' => $this->other->id])->assertRedirect('/login?w='.$this->other->id);
    $this->withHeader('X-RI-Warehouse', $this->other->id)->withUnencryptedCookie('ri_warehouse', $this->other->id)->get('/login')->assertRedirect('/activate');
});
