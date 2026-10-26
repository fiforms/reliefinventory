<?php

use App\Models\Trust;
use App\Support\TrustPermission;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

function fedSpec(): array
{
    return Yaml::parseFile(base_path('rstation/federation.openapi.yaml'));
}

test('every documented federation operation is routed, and no federation route is undocumented', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/federation/v1/') && ! str_contains($r->uri(), 'test-needs'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => [strtolower($m), substr($r->uri(), strlen('api/federation/v1')), $r]));

    $documented = collect(fedSpec()['paths'])->flatMap(fn ($ops, $path) => collect($ops)->keys()->map(fn ($m) => [$m, $path]));

    foreach ($documented as [$method, $path]) {
        $route = $routes->first(fn ($r) => $r[0] === $method && $r[1] === $path);
        expect($route !== null)->toBeTrue("{$method} {$path} is documented but not routed");
        $middleware = collect($route[2]->gatherMiddleware());
        $expected = match ($path) {
            '/pair' => null,
            default => 'trust-auth',
        };
        $actual = $middleware->first(fn ($m) => str_starts_with($m, 'trust-auth') || str_starts_with($m, 'device-auth'));
        expect($actual === null ? null : explode(':', $actual)[0])->toBe($expected, "{$method} {$path}: authentication disagrees with the spec");
    }
    foreach ($routes as [$method, $path]) {
        expect($documented->contains(fn ($d) => $d === [$method, $path]))->toBeTrue("{$method} {$path} is routed but not documented");
    }
});

test('the pair request in the spec lists every field the server requires', function () {
    $schema = fedSpec()['components']['schemas']['PairRequest'];
    expect($schema['required'])->toEqualCanonicalizing(['code', 'kind', 'uuid', 'name', 'public_key', 'signature'])
        ->and($schema['properties']['kind']['enum'])->toBe(Trust::KINDS);
});

test('the documented permission keys are exactly the ones the server knows', function () {
    $doc = file_get_contents(base_path('rstation/FEDERATION_AUTH.md'));
    foreach (TrustPermission::keys() as $key) {
        expect($doc)->toContain("`{$key}`");
    }
});
