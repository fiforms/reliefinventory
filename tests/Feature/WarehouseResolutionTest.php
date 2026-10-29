<?php

use App\Models\Category;
use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Models\User;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\SecondWarehouse;

// MULTI_WAREHOUSE.md phase 2: which warehouse a request works in (§3, §4), and the tab guard.

beforeEach(function () {
    $this->default = Warehouse::default();
    $this->other = SecondWarehouse::make($this);

    $whoami = fn (Request $request) => [
        'warehouse' => app(WarehouseContext::class)->current()->id,
        'path' => $request->getPathInfo(),
        'original' => $request->attributes->get('ri.original_uri'),
        'link' => url('/api/test-whoami'),
    ];
    Route::get('/test-whoami', $whoami);
    Route::get('/api/test-whoami', $whoami);
    Route::middleware('web')->get('/test-guarded', fn () => ['marker' => session('marker')]);
    Route::middleware('web')->post('/test-guarded', fn () => ['ok' => true]);
});

afterEach(fn () => app(WarehouseContext::class)->switchTo(Warehouse::default()));

test('with no choice a request works in the default warehouse', function () {
    $this->get('/test-whoami')->assertJson(['warehouse' => $this->default->id]);
});

test('the selector cookie chooses the warehouse; one that is unknown or not active is ignored', function () {
    $this->withUnencryptedCookie('ri_warehouse', $this->other->id)->get('/test-whoami')->assertJson(['warehouse' => $this->other->id]);
    $this->withUnencryptedCookie('ri_warehouse', 'ZZZZZZZZ')->get('/test-whoami')->assertJson(['warehouse' => $this->default->id]);

    $this->other->forceFill(['status' => 'suspended'])->save();
    $this->withUnencryptedCookie('ri_warehouse', $this->other->id)->get('/test-whoami')->assertJson(['warehouse' => $this->default->id]);
});

test('?w= sets the cookie and redirects to the same URL without it, keeping the rest of the query as sent', function () {
    $response = $this->get('/test-whoami?a=1&w='.strtolower($this->other->id).'&email=x%40y.org&signature=a%2Bb');

    $response->assertRedirect('/test-whoami?a=1&email=x%40y.org&signature=a%2Bb')
        ->assertCookie('ri_warehouse', $this->other->id, false);

    // The same answer for an id that doesn't exist: a redirect, and no cookie.
    $unknown = $this->get('/test-whoami?w=ZZZZZZZZ');
    $unknown->assertRedirect('/test-whoami');
    expect($unknown->headers->getCookies())->toBe([]);
});

test('a mapped hostname is locked to its warehouse: the cookie and ?w= are ignored there', function () {
    WarehouseDomain::create(['hostname' => 'Other.Example.test', 'warehouse_id' => $this->other->id, 'is_primary' => true]);

    $this->withUnencryptedCookie('ri_warehouse', $this->default->id)
        ->get('http://other.example.test/test-whoami')->assertJson(['warehouse' => $this->other->id]);

    $redirect = $this->get('http://other.example.test/test-whoami?w='.$this->default->id);
    $redirect->assertRedirect('http://other.example.test/test-whoami');
    expect($redirect->headers->getCookies())->toBe([]);
});

test('a /w/<id> prefix on a machine URL is stripped for routing, keeps the URI as sent, and roots links in the warehouse', function () {
    $this->get('/w/'.$this->other->id.'/api/test-whoami?x=1')->assertOk()->assertJson([
        'warehouse' => $this->other->id,
        'path' => '/api/test-whoami',
        'original' => '/w/'.$this->other->id.'/api/test-whoami?x=1',
    ])->assertJsonPath('link', rtrim(config('app.url'), '/').'/w/'.$this->other->id.'/api/test-whoami');

    // Unprefixed machine URLs stay on the default warehouse, whatever the browser cookie says.
    $this->get('/api/test-whoami')->assertJson(['warehouse' => $this->default->id, 'original' => null])
        ->assertJsonPath('link', rtrim(config('app.url'), '/').'/api/test-whoami');
    $this->get('/w/'.$this->other->id.'/up')->assertOk();
});

test('a prefix naming an unknown warehouse, or another warehouse on a mapped host, is refused', function () {
    $this->get('/w/ZZZZZZZZ/api/test-whoami')->assertNotFound();

    WarehouseDomain::create(['hostname' => 'default.example.test', 'warehouse_id' => $this->default->id]);
    $this->get('http://default.example.test/w/'.$this->other->id.'/api/test-whoami')->assertNotFound();
    $this->get('http://default.example.test/w/'.$this->default->id.'/api/test-whoami')->assertJson(['warehouse' => $this->default->id]);
});

test('a prefixed browser URL chooses the warehouse and continues without the prefix', function () {
    $this->get('/w/'.$this->other->id.'/device-session/abc?x=1')
        ->assertRedirect('/device-session/abc?x=1')
        ->assertCookie('ri_warehouse', $this->other->id, false);
    $this->post('/w/'.$this->other->id.'/login')->assertNotFound();
});

test('each warehouse sees only its own data', function () {
    $context = app(WarehouseContext::class);
    $context->run($this->other, fn () => Category::create(['name' => 'Only in the other warehouse']));

    expect(Category::where('name', 'Only in the other warehouse')->exists())->toBeFalse()
        ->and($context->run($this->other, fn () => Category::where('name', 'Only in the other warehouse')->exists()))->toBeTrue();
});

test('the tab guard refuses a request naming another warehouse', function () {
    $this->withCredentials()->withUnencryptedCookie('ri_warehouse', $this->other->id)
        ->withHeader('X-RI-Warehouse', $this->default->id)
        ->postJson('/test-guarded')->assertStatus(409)->assertJson(['warehouse_switched' => true]);

    $this->withHeader('X-RI-Warehouse', $this->other->id)
        ->postJson('/test-guarded')->assertOk();
});

test('with several warehouses the tab guard fails closed: a change or an Inertia visit must name its warehouse', function () {
    $this->postJson('/test-guarded')->assertStatus(409);
    $this->post('/test-guarded')->assertRedirect('/warehouse-switched');
    $this->post('/test-guarded', ['_w' => $this->default->id])->assertOk();

    // A plain GET (a download) without one is fine; with one that doesn't match it isn't.
    $this->get('/test-guarded')->assertOk();
    $this->get('/test-guarded?_w='.$this->other->id)->assertRedirect('/warehouse-switched');

    $this->withHeader('X-Inertia', 'true')->get('/test-guarded')
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url('/warehouse-switched'));
});

test('with one warehouse the guard asks for nothing', function () {
    app(WarehouseContext::class)->run($this->other, fn () => null);
    $this->other->delete();

    $this->postJson('/test-guarded')->assertOk();
});

test('a session never carries into another warehouse', function () {
    $this->withSession(['warehouse_id' => $this->other->id, 'marker' => 'kept'])
        ->get('/test-guarded')->assertJson(['marker' => null]);
    $this->withSession(['warehouse_id' => $this->default->id, 'marker' => 'kept'])
        ->get('/test-guarded')->assertJson(['marker' => 'kept']);
});

test('pages say which warehouse they belong to', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page
        ->where('warehouse.id', $this->default->id)
        ->where('warehouse.multiple', true)
        ->where('warehouse.storageSuffix', ''));
});

test('links that leave the browser name the warehouse, and never come from the Host header', function () {
    config(['app.url' => 'https://relief.example.org']);

    expect($this->default->publicUrl('/login'))->toBe('https://relief.example.org/login?w='.$this->default->id)
        ->and($this->other->publicUrl('/join'))->toBe('https://relief.example.org/join?w='.$this->other->id)
        ->and($this->other->withSelector('http://10.0.0.5/join?x=1#top'))->toBe('http://10.0.0.5/join?x=1&w='.$this->other->id.'#top');

    WarehouseDomain::create(['hostname' => 'other.example.org', 'warehouse_id' => $this->other->id, 'is_primary' => true]);
    expect($this->other->publicUrl('/join'))->toBe('https://other.example.org/join');
});

test('long-lived cookies are per warehouse; the default warehouse keeps the old names', function () {
    $context = app(WarehouseContext::class);

    expect($context->cookieName('pin_device_token'))->toBe('pin_device_token')
        ->and($context->run($this->other, fn () => $context->cookieName('pin_device_token')))->toBe('pin_device_token_'.strtolower($this->other->id));
});
