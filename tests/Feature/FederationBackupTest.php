<?php

use App\Models\Backup;
use App\Models\Trust;
use App\Models\TrustAuditLog;
use App\Models\TrustPairingCode;
use App\Services\BackupService;
use App\Services\Federation\TrustService;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-fedbackup-test-'.bin2hex(random_bytes(4));
    mkdir($this->tmp, 0777, true);
    config(['backup.dir' => $this->tmp]);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->tmp));
});

const BACKUPS = '/api/federation/v1/backups';

/** A complete backup row with a real file behind it. */
function finishedBackup(string $tmp, string $content = 'PK-not-really-a-zip', string $scope = 'database'): Backup
{
    // In the warehouse's own backup folder (BackupService::dir()), which the backup row's path resolves to.
    $folder = app(BackupService::class)->dir();
    @mkdir($folder, 0750, true);
    file_put_contents("{$folder}/relief-backup-test-{$scope}.zip", $content);

    return Backup::create([
        'status' => 'complete', 'scope' => $scope, 'filename' => "relief-backup-test-{$scope}.zip",
        'size_bytes' => strlen($content), 'file_count' => 1, 'finished_at' => now(), 'expires_at' => now()->addDays(14),
    ]);
}

test('a backup client pairs with a code and no location, and gets no managed device', function () {
    $peer = fedPeer('backup_client');
    $trust = fedEnroll($peer, ['operator.full_read']);

    expect($trust->kind)->toBe('backup_client')->and($trust->isClient())->toBeFalse()
        ->and($trust->inboundPermissions())->toBe(['operator.full_read'])
        ->and($trust->managedDevice)->toBeNull();
});

test('a code made for another kind cannot pair a backup client, nor the reverse', function () {
    $peer = fedPeer('backup_client');
    [$code] = TrustService::createPairingCode('instance', ['operator.full_read'], null, null, null);
    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $code))->assertStatus(422);

    [$client] = TrustService::createPairingCode('client', ['operator.full_read'], null, null, null);
    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $client))->assertStatus(422);
    expect(Trust::count())->toBe(0);
});

test('the backup endpoints need the operator.full_read grant', function () {
    $without = fedPeer('backup_client');
    fedEnroll($without, ['feedback.send']);
    fedCall($this, $without, 'GET', BACKUPS)->assertForbidden();
    fedCall($this, $without, 'POST', BACKUPS, json_encode(['scope' => 'database']))->assertForbidden();

    $stranger = fedPeer('backup_client');
    fedCall($this, $stranger, 'GET', BACKUPS)->assertUnauthorized();
    $this->getJson(BACKUPS)->assertUnauthorized();
});

test('a revoked backup client is refused at once', function () {
    $peer = fedPeer('backup_client');
    $trust = fedEnroll($peer, ['operator.full_read']);
    fedCall($this, $peer, 'GET', BACKUPS)->assertOk();

    TrustService::revoke($trust, null);
    fedCall($this, $peer, 'GET', BACKUPS)->assertUnauthorized();
});

test('it lists backups newest first, with the size and a failed one\'s error', function () {
    $peer = fedPeer('backup_client');
    fedEnroll($peer, ['operator.full_read']);
    $done = finishedBackup($this->tmp);
    $failed = Backup::create(['status' => 'failed', 'scope' => 'full', 'error' => 'disk full']);

    $res = fedCall($this, $peer, 'GET', BACKUPS)->assertOk();
    expect(array_column($res->json('backups'), 'id'))->toBe([$failed->id, $done->id])
        ->and($res->json('backups.0.error'))->toBe('disk full')
        ->and($res->json('backups.1.size_bytes'))->toBe(strlen('PK-not-really-a-zip'))
        ->and($res->json('backups.1.sha256'))->toBeNull(); // the list never hashes
});

test('a complete backup whose file is gone reads as missing and cannot be downloaded', function () {
    $peer = fedPeer('backup_client');
    fedEnroll($peer, ['operator.full_read']);
    $b = finishedBackup($this->tmp);
    unlink($b->path());

    fedCall($this, $peer, 'GET', BACKUPS.'/'.$b->id)->assertOk()->assertJsonPath('backup.status', 'missing')->assertJsonPath('backup.sha256', null);
    fedCall($this, $peer, 'GET', BACKUPS.'/'.$b->id.'/download')->assertNotFound();
});

test('show returns the SHA-256 of the whole file and download streams exactly those bytes', function () {
    $peer = fedPeer('backup_client');
    fedEnroll($peer, ['operator.full_read']);
    $b = finishedBackup($this->tmp, str_repeat('abcdef', 1000));

    fedCall($this, $peer, 'GET', BACKUPS.'/'.$b->id)->assertOk()
        ->assertJsonPath('backup.sha256', hash('sha256', str_repeat('abcdef', 1000)));

    $res = fedCall($this, $peer, 'GET', BACKUPS.'/'.$b->id.'/download')->assertOk();
    expect($res->baseResponse->getFile()->getPathname())->toBe($b->path())
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');
});

test('a hash is recomputed when the file changes', function () {
    $peer = fedPeer('backup_client');
    fedEnroll($peer, ['operator.full_read']);
    $b = finishedBackup($this->tmp, 'one');
    $first = fedCall($this, $peer, 'GET', BACKUPS.'/'.$b->id)->json('backup.sha256');

    file_put_contents($b->path(), 'two-and-longer');
    $second = fedCall($this, $peer, 'GET', BACKUPS.'/'.$b->id)->json('backup.sha256');
    expect($first)->toBe(hash('sha256', 'one'))->and($second)->toBe(hash('sha256', 'two-and-longer'));
});

test('a download is audited once, not again for each resumed range', function () {
    $peer = fedPeer('backup_client');
    $trust = fedEnroll($peer, ['operator.full_read']);
    $b = finishedBackup($this->tmp, str_repeat('x', 5000));
    $uri = BACKUPS.'/'.$b->id.'/download';

    fedCall($this, $peer, 'GET', $uri)->assertOk();
    $audited = fn () => TrustAuditLog::where('trust_id', $trust->id)->where('event', 'backup_downloaded')->count();
    expect($audited())->toBe(1);

    $server = ['HTTP_RANGE' => 'bytes=1000-'];
    foreach (fedHeaders($peer, 'GET', $uri) as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $partial = $this->call('GET', $uri, [], [], [], $server)->assertStatus(206);
    expect($partial->headers->get('Content-Range'))->toBe('bytes 1000-4999/5000')->and($audited())->toBe(1);
});

test('asking for a backup starts one, and a second ask while it runs is a 409 naming it', function () {
    Process::fake();
    $peer = fedPeer('backup_client');
    $trust = fedEnroll($peer, ['operator.full_read']);

    $res = fedCall($this, $peer, 'POST', BACKUPS, json_encode(['scope' => 'full']))->assertStatus(202);
    $id = $res->json('backup.id');
    expect($res->json('backup.status'))->toBe('queued')->and($res->json('backup.scope'))->toBe('full')
        ->and(TrustAuditLog::where('trust_id', $trust->id)->where('event', 'backup_requested')->exists())->toBeTrue();
    Process::assertRan(fn ($p) => str_contains($p->command, "backup:run {$id}"));

    $again = fedCall($this, $peer, 'POST', BACKUPS, json_encode(['scope' => 'database']))->assertStatus(409);
    expect($again->json('backup.id'))->toBe($id);
    expect(Backup::count())->toBe(1);
});

test('only database and full scopes may be requested', function () {
    Process::fake();
    $peer = fedPeer('backup_client');
    fedEnroll($peer, ['operator.full_read']);

    fedCall($this, $peer, 'POST', BACKUPS, json_encode(['scope' => 'storage']))->assertStatus(422);
    fedCall($this, $peer, 'POST', BACKUPS, json_encode([]))->assertStatus(422);
    expect(Backup::count())->toBe(0);
});

test('a session token works for the whole flow, so a client signs once', function () {
    Process::fake();
    $peer = fedPeer('backup_client');
    fedEnroll($peer, ['operator.full_read']);
    $token = fedCall($this, $peer, 'POST', '/api/federation/v1/session', '{}')->assertOk()->json('session_token');
    $b = finishedBackup($this->tmp);

    $this->withToken($token)->getJson(BACKUPS)->assertOk();
    $this->withToken($token)->getJson(BACKUPS.'/'.$b->id)->assertOk();
    $this->withToken($token)->get(BACKUPS.'/'.$b->id.'/download')->assertOk();
    $this->withToken($token)->postJson(BACKUPS, ['scope' => 'database'])->assertStatus(202);
});

test('polling a backup does not use up the budget for starting one, and each trust has its own', function () {
    Process::fake();
    $peer = fedPeer('backup_client');
    $other = fedPeer('backup_client');
    fedEnroll($peer, ['operator.full_read']);
    fedEnroll($other, ['operator.full_read']);
    $b = finishedBackup($this->tmp);

    for ($i = 0; $i < 30; $i++) {
        fedCall($this, $peer, 'GET', BACKUPS.'/'.$b->id)->assertOk();
    }
    fedCall($this, $peer, 'POST', BACKUPS, json_encode(['scope' => 'database']))->assertStatus(202);

    // starting backups is limited to 6 a minute per trust; the 7th is refused, another trust is unaffected
    foreach (range(1, 5) as $_) {
        fedCall($this, $peer, 'POST', BACKUPS, json_encode(['scope' => 'database']))->assertStatus(409);
    }
    fedCall($this, $peer, 'POST', BACKUPS, json_encode(['scope' => 'database']))->assertStatus(429);
    fedCall($this, $other, 'POST', BACKUPS, json_encode(['scope' => 'database']))->assertStatus(409);
});

test('the Trust Console can create a backup client code, which needs no location', function () {
    $this->actingAs(userWithPermissions('manage-trusts'))->postJson('/json/trusts/pairing-codes', [
        'kind' => 'backup_client', 'grants' => ['operator.full_read'],
    ])->assertCreated();
    expect(TrustPairingCode::first()->kind)->toBe('backup_client');
    $this->getJson('/json/trusts')->assertOk()->assertJsonFragment(['kinds' => Trust::KINDS]);
});
