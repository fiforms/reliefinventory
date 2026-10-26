<?php

use App\Models\ManagedDevice;
use App\Models\PrintJob;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

/*
 * Drift detector for rstation/print-extension.openapi.yaml: the file is only
 * useful while it matches the server, so this checks that every documented
 * operation exists, that real responses fit the documented schemas, and that
 * the documented limits and enums are the ones the server enforces. It is not
 * a full OpenAPI validator (see swagger-cli for the file's own validity).
 */

function oaSpec(): array
{
    static $spec = null;

    return $spec ??= Yaml::parseFile(base_path('rstation/print-extension.openapi.yaml'));
}

function oaResolve(array $schema): array
{
    if (isset($schema['$ref'])) {
        $node = oaSpec();
        foreach (explode('/', ltrim($schema['$ref'], '#/')) as $segment) {
            $node = $node[$segment];
        }

        return oaResolve($node);
    }
    if (isset($schema['allOf'])) {
        // Merge the parts, then let the siblings (e.g. `nullable`) apply on top.
        return array_merge(array_merge(...array_map(fn ($part) => oaResolve($part), $schema['allOf'])), array_diff_key($schema, ['allOf' => 1]));
    }

    return $schema;
}

/** Light structural check: required keys, no undocumented keys, basic types, enums, max lengths. */
function oaAssertShape(mixed $data, array $schema, string $where = 'body'): void
{
    $schema = oaResolve($schema);

    if ($data === null) {
        expect($schema['nullable'] ?? false)->toBeTrue("{$where} is null but not nullable");

        return;
    }
    $type = $schema['type'] ?? null;
    if ($type) {
        $fits = match ($type) {
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'string' => is_string($data),
            'boolean' => is_bool($data),
            'array' => is_array($data) && array_is_list($data),
            'object' => is_array($data) && ($data === [] || ! array_is_list($data)),
            default => true,
        };
        expect($fits)->toBeTrue("{$where} should be {$type}");
    }
    if (isset($schema['enum'])) {
        expect(in_array($data, $schema['enum'], true))->toBeTrue("{$where} ({$data}) is not in the documented enum");
    }
    if (isset($schema['maxLength'])) {
        expect(strlen($data) <= $schema['maxLength'])->toBeTrue("{$where} exceeds maxLength");
    }
    if ($type === 'array') {
        foreach ($data as $i => $item) {
            oaAssertShape($item, $schema['items'], "{$where}[{$i}]");
        }
    }
    if ($type === 'object') {
        foreach ($schema['required'] ?? [] as $key) {
            expect(array_key_exists($key, $data))->toBeTrue("{$where} is missing required key {$key}");
        }
        foreach ($data as $key => $value) {
            if (isset($schema['properties'][$key])) {
                oaAssertShape($value, $schema['properties'][$key], "{$where}.{$key}");
            } elseif (! ($schema['additionalProperties'] ?? false)) {
                fail("{$where}.{$key} is not documented in the OpenAPI schema");
            }
        }
    }
}

function oaBeat(string $token, array $body)
{
    return test()->withToken($token)->postJson('/api/devices/heartbeat', $body);
}

function oaQueuedJob(ManagedDevice $device): PrintJob
{
    Storage::fake(config('printing.disk'));
    Storage::disk(config('printing.disk'))->put('print-jobs/oa.pdf', '%PDF-1.4 fake');
    $printer = $device->printers()->firstOrFail();
    $printer->forceFill(['media' => 'label_large', 'active' => true])->save();

    return PrintJob::create([
        'printer_id' => $printer->id, 'kiosk_location_id' => $printer->kiosk_location_id, 'media' => 'label_large',
        'title' => 'Pallet label', 'pdf_path' => 'print-jobs/oa.pdf', 'copies' => 2,
    ]);
}

test('the spec file parses and declares the documented operations', function () {
    expect(oaSpec()['openapi'])->toStartWith('3.')
        ->and(array_keys(oaSpec()['paths']))->toBe([
            '/heartbeat', '/print-jobs', '/print-jobs/{id}/claim', '/print-jobs/{id}/payload', '/print-jobs/{id}/result',
        ]);
});

test('every documented operation is a real device-authenticated route, and no print route is undocumented', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/devices/'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => [strtolower($m), substr($r->uri(), strlen('api/devices')), $r]));

    $documented = collect(oaSpec()['paths'])->flatMap(fn ($ops, $path) => collect($ops)->keys()->map(fn ($m) => [$m, $path]));

    foreach ($documented as [$method, $path]) {
        $route = $routes->first(fn ($r) => $r[0] === $method && $r[1] === $path);
        expect($route !== null)->toBeTrue("{$method} {$path} is documented but not routed");
        expect(collect($route[2]->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'device-auth')))->toBeTrue("{$method} {$path} is not device-authenticated");
    }

    $routed = $routes->filter(fn ($r) => str_starts_with($r[1], '/print-jobs') || $r[1] === '/heartbeat');
    foreach ($routed as [$method, $path]) {
        expect($documented->contains(fn ($d) => $d === [$method, $path]))->toBeTrue("{$method} {$path} is routed but not documented");
    }
});

test('real responses conform to the documented schemas', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => 'ri.example.org', 'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https', 'printing.realtime' => ['host' => null, 'port' => null, 'scheme' => null],
    ]);
    [$device, $token] = pairPrintServer('oa-1');

    $heartbeat = oaBeat($token, ['print_platform' => 'linux', 'print_printers' => [['queue_name' => 'Zebra', 'state' => 'idle']]])->assertOk()->json();
    oaAssertShape($heartbeat, oaSpec()['components']['schemas']['PrintHeartbeatResponse'], 'heartbeat');
    oaAssertShape($heartbeat['print_realtime'], ['$ref' => '#/components/schemas/PrintRealtime'], 'print_realtime');

    $job = oaQueuedJob($device);
    $list = test()->withToken($token)->getJson('/api/devices/print-jobs')->assertOk()->json();
    oaAssertShape($list, ['$ref' => '#/components/schemas/PrintJobList'], 'job list');
    expect($list['jobs'])->toHaveCount(1);

    $claim = test()->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk()->json();
    oaAssertShape($claim, ['$ref' => '#/components/schemas/ClaimResponse'], 'claim');

    test()->withToken($token)->get("/api/devices/print-jobs/{$job->id}/payload")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $result = test()->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'printed'])->assertOk()->json();
    oaAssertShape($result, ['$ref' => '#/components/schemas/ResultResponse'], 'result');
});

test('the realtime object is null when realtime is off, as documented', function () {
    [, $token] = pairPrintServer('oa-1');

    $heartbeat = oaBeat($token, ['print_printers' => [['queue_name' => 'Zebra']]])->assertOk()->json();

    oaAssertShape($heartbeat, oaSpec()['components']['schemas']['PrintHeartbeatResponse'], 'heartbeat');
    expect($heartbeat['print_realtime'])->toBeNull();
});

test('the documented enums and limits are the ones the server enforces', function () {
    [, $token] = pairPrintServer('oa-1');
    $schemas = oaSpec()['components']['schemas'];

    // print_platform enum
    foreach ($schemas['PrintHeartbeatRequest']['properties']['print_platform']['enum'] as $platform) {
        oaBeat($token, ['print_platform' => $platform])->assertOk();
    }
    oaBeat($token, ['print_platform' => 'amiga'])->assertUnprocessable();

    // reported queue state enum, queue_name / state_message lengths
    $printer = $schemas['ReportedPrinter']['properties'];
    foreach ($printer['state']['enum'] as $state) {
        oaBeat($token, ['print_printers' => [['queue_name' => 'Q', 'state' => $state]]])->assertOk();
    }
    oaBeat($token, ['print_printers' => [['queue_name' => 'Q', 'state' => 'on-fire']]])->assertUnprocessable();
    oaBeat($token, ['print_printers' => [['queue_name' => str_repeat('a', $printer['queue_name']['maxLength'])]]])->assertOk();
    oaBeat($token, ['print_printers' => [['queue_name' => str_repeat('a', $printer['queue_name']['maxLength'] + 1)]]])->assertUnprocessable();
    oaBeat($token, ['print_printers' => [['queue_name' => 'Q', 'state_message' => str_repeat('m', $printer['state_message']['maxLength'])]]])->assertOk();
    oaBeat($token, ['print_printers' => [['queue_name' => 'Q', 'state_message' => str_repeat('m', $printer['state_message']['maxLength'] + 1)]]])->assertUnprocessable();

    // maxItems
    $max = $schemas['PrintHeartbeatRequest']['properties']['print_printers']['maxItems'];
    $queues = fn (int $n) => array_map(fn ($i) => ['queue_name' => "q{$i}"], range(1, $n));
    oaBeat($token, ['print_printers' => $queues($max)])->assertOk();
    oaBeat($token, ['print_printers' => $queues($max + 1)])->assertUnprocessable();
});

test('the documented result limits and statuses are enforced, and media keys match the config', function () {
    [$device, $token] = pairPrintServer('oa-1');
    oaBeat($token, ['print_printers' => [['queue_name' => 'Zebra']]])->assertOk();
    $schemas = oaSpec()['components']['schemas'];
    $job = oaQueuedJob($device);
    test()->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    $max = $schemas['ResultRequest']['properties']['error']['maxLength'];
    test()->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'bogus'])->assertUnprocessable();
    test()->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'failed', 'error' => str_repeat('e', $max + 1)])->assertUnprocessable();
    test()->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'failed', 'error' => str_repeat('e', $max)])->assertOk();

    // The prose lists the media keys the config knows; if one is added or removed the doc must follow.
    $described = oaSpec()['components']['schemas']['PrintJob']['properties']['media']['description'];
    foreach (array_keys(config('printing.media')) as $key) {
        expect(str_contains($described, $key))->toBeTrue("media key {$key} is not mentioned in the OpenAPI description");
    }
    // Copies bounds match what the print endpoint accepts.
    expect($schemas['PrintJob']['properties']['copies']['maximum'])->toBe(99);
});
