<?php

// The instance-level backup trust (App\Models\Control\InstanceTrust): a computer paired to the whole instance by an instance administrator, for
// the RI Launcher's whole-instance backups at /api/instance/v1. Same signing as federation/v1 (tests/Pest.php fed* helpers), a different table.

use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstancePairingCode;
use App\Models\Control\InstanceTrust;
use App\Models\Control\ProvisioningCommand;
use App\Models\Trust;
use App\Services\Federation\InstanceTrustService;
use App\Services\Federation\TrustService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-insttrust-test-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/instance', 0777, true);
    config(['backup.dir' => $this->tmp, 'system.runtime' => 'docker']);
    InstanceTrust::query()->delete();
    InstancePairingCode::query()->delete();
    ProvisioningCommand::whereIn('command', ['backup-now', 'backup-now-files', 'backup-sched'])->delete();
    $this->accounts = [];
    $this->zip = fn (string $name, string $content = 'PK-not-really-a-zip') => tap($this->tmp.'/instance/'.$name, fn ($path) => file_put_contents($path, $content));

    /** A computer (key pair) with an approved instance trust, made through the real pairing path. */
    $this->pair = function (): array {
        $peer = fedPeer(InstanceTrustService::KIND);
        [$code] = InstanceTrustService::createPairingCode(null, 'admin@example.org');
        $this->postJson('/api/instance/v1/pair', fedPairBody($peer, $code))->assertCreated();

        return $peer;
    };
    $this->admin = function (bool $instanceAdmin = true) {
        $user = userWithPermissions('admin-system');
        $account = new Account(['email' => 'itr-'.Str::random(6).'@example.org']);
        $account->forceFill(['password' => Hash::make('x-password-1'), 'email_verified_at' => now(), 'is_instance_admin' => $instanceAdmin])->save();
        $user->forceFill(['account_id' => $account->id])->save();
        $this->accounts[] = $account;

        return $user->fresh();
    };
    // The test client keeps headers between requests in one test: a bearer call must not leak into a signed one, nor the reverse.
    $this->asToken = function (string $token, array $extra = []) {
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token] + $extra);
    };
    $this->session = fn (array $peer) => signedCall($this, $peer, 'POST', '/api/instance/v1/session', '{}')->assertOk()->json('session_token');
});

afterEach(function () {
    InstanceTrust::query()->delete();
    InstancePairingCode::query()->delete();
    ProvisioningCommand::whereIn('command', ['backup-now', 'backup-now-files', 'backup-sched'])->delete();
    foreach ($this->accounts as $account) {
        $account->delete();
    }
    exec('rm -rf '.escapeshellarg($this->tmp));
});

const INSTANCE_API = '/api/instance/v1';

/** A signed call (tests/Pest.php fedCall) with no headers left over from an earlier bearer call. */
function signedCall($test, ...$args)
{
    $test->flushHeaders();

    return fedCall($test, ...$args);
}
const ZIP_A = 'relief-instance-20260204-030500-database-scheduled-daily.zip';

test('a computer pairs with a code, proves it holds its key, and holds only the instance backup grant', function () {
    $peer = fedPeer(InstanceTrustService::KIND);
    [$code, $row] = InstanceTrustService::createPairingCode('Office laptop', 'admin@example.org');

    $res = $this->postJson(INSTANCE_API.'/pair', fedPairBody($peer, $code))->assertCreated();

    $trust = InstanceTrust::firstOrFail();
    expect($res->json('permissions'))->toBe(['instance.backup'])->and($res->json('server'))->toHaveKeys(['uuid', 'name', 'public_key'])
        ->and($trust->peer_uuid)->toBe($peer['uuid'])->and($trust->public_key)->toBe($peer['public_key'])
        ->and($trust->approved_by)->toBe('admin@example.org')->and($trust->isApproved())->toBeTrue()
        ->and($row->fresh()->used_at)->not->toBeNull()
        ->and(InstanceAuditLog::where('action', 'instance.trust_enrolled')->exists())->toBeTrue()
        ->and($row->code_hash)->not->toBe($code);   // only a hash is kept
});

test('a code works once, expires, and needs the key to be proven', function () {
    $peer = fedPeer(InstanceTrustService::KIND);
    [$code, $row] = InstanceTrustService::createPairingCode(null, 'admin@example.org');

    // signed by some other key: the same generic answer as a wrong code, and the code is not used up
    $other = fedPeer(InstanceTrustService::KIND);
    $this->postJson(INSTANCE_API.'/pair', fedPairBody($peer, $code, $other['secret']))->assertStatus(422)->assertJsonPath('message', 'Invalid or expired code.');
    expect($row->fresh()->used_at)->toBeNull();

    $this->postJson(INSTANCE_API.'/pair', fedPairBody($peer, $code))->assertCreated();
    $this->postJson(INSTANCE_API.'/pair', fedPairBody(fedPeer(InstanceTrustService::KIND), $code))->assertStatus(422);   // used

    [$late] = InstanceTrustService::createPairingCode(null, 'admin@example.org');
    InstancePairingCode::query()->update(['expires_at' => now()->subMinute()]);
    $this->postJson(INSTANCE_API.'/pair', fedPairBody(fedPeer(InstanceTrustService::KIND), $late))->assertStatus(422);
    expect(InstanceTrust::count())->toBe(1);
});

test('instance codes and warehouse codes cannot pair each other\'s kind', function () {
    $peer = fedPeer(InstanceTrustService::KIND);
    [$warehouseCode] = TrustService::createPairingCode('backup_client', ['operator.full_read'], null, null, null);
    $this->postJson(INSTANCE_API.'/pair', fedPairBody($peer, $warehouseCode))->assertStatus(422);

    [$instanceCode] = InstanceTrustService::createPairingCode(null, 'admin@example.org');
    $launcher = fedPeer('backup_client');
    $this->postJson('/api/federation/v1/pair', fedPairBody($launcher, $instanceCode))->assertStatus(422);

    // and a computer cannot claim another kind of trust through the instance endpoint
    $this->postJson(INSTANCE_API.'/pair', ['kind' => 'backup_client'] + fedPairBody($peer, $instanceCode))->assertStatus(422)->assertJsonValidationErrors('kind');
    expect(InstanceTrust::count())->toBe(0)->and(Trust::count())->toBe(0);
});

test('a signed call earns a session token that carries the grant, and a stranger or an unsigned call gets nothing', function () {
    $peer = ($this->pair)();
    $token = ($this->session)($peer);

    expect($token)->toStartWith('RII-');
    ($this->asToken)($token)->getJson(INSTANCE_API.'/grants')->assertOk()->assertJsonPath('permissions', ['instance.backup']);
    ($this->asToken)($token)->getJson(INSTANCE_API.'/backups')->assertOk();

    $this->flushHeaders();
    $this->getJson(INSTANCE_API.'/backups')->assertUnauthorized();
    ($this->asToken)('RII-nonsense')->getJson(INSTANCE_API.'/backups')->assertUnauthorized();
    signedCall($this, fedPeer(InstanceTrustService::KIND), 'GET', INSTANCE_API.'/backups')->assertUnauthorized();
    // a session token is not enough to ask for a session
    ($this->asToken)($token)->postJson(INSTANCE_API.'/session', [])->assertUnauthorized();
});

test('a signature is used once, and a clock that is far off is a 400 with the server time, never a 401', function () {
    $peer = ($this->pair)();

    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups', '', null, 'nonce-one')->assertOk();
    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups', '', null, 'nonce-one')->assertUnauthorized();   // replay

    $late = signedCall($this, $peer, 'GET', INSTANCE_API.'/backups', '', time() - 3600);
    $late->assertStatus(400);
    expect($late->headers->get('X-RI-Server-Time'))->not->toBeNull();
});

test('a warehouse trust is nothing here and an instance trust is nothing at a warehouse', function () {
    $warehousePeer = fedPeer('backup_client');
    fedEnroll($warehousePeer, ['operator.full_read']);
    signedCall($this, $warehousePeer, 'GET', INSTANCE_API.'/backups')->assertUnauthorized();
    signedCall($this, $warehousePeer, 'GET', INSTANCE_API.'/grants')->assertUnauthorized();
    signedCall($this, $warehousePeer, 'GET', '/api/federation/v1/backups')->assertOk();

    $instancePeer = ($this->pair)();
    signedCall($this, $instancePeer, 'GET', '/api/federation/v1/backups')->assertUnauthorized();
    signedCall($this, $instancePeer, 'GET', INSTANCE_API.'/backups')->assertOk();
});

test('a trust without the grant is refused with a 403', function () {
    $peer = ($this->pair)();
    InstanceTrust::query()->update(['permissions' => json_encode([])]);

    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups')->assertForbidden();
    signedCall($this, $peer, 'POST', INSTANCE_API.'/backups', json_encode(['scope' => 'database']))->assertForbidden();
});

test('revoking stops a computer and its session at once, and a new code pairs it again with a new session', function () {
    $peer = ($this->pair)();
    $token = ($this->session)($peer);
    ($this->asToken)($token)->getJson(INSTANCE_API.'/backups')->assertOk();

    InstanceTrustService::revoke(InstanceTrust::firstOrFail(), 'admin@example.org');
    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups')->assertUnauthorized();
    ($this->asToken)($token)->getJson(INSTANCE_API.'/backups')->assertUnauthorized();
    expect(InstanceAuditLog::where('action', 'instance.trust_revoked')->exists())->toBeTrue();

    [$code] = InstanceTrustService::createPairingCode(null, 'admin@example.org');
    $this->postJson(INSTANCE_API.'/pair', fedPairBody($peer, $code))->assertCreated();
    expect(InstanceTrust::count())->toBe(1)                                          // the same computer, re-attached
        ->and(InstanceTrust::first()->isApproved())->toBeTrue();
    ($this->asToken)($token)->getJson(INSTANCE_API.'/backups')->assertUnauthorized();   // the old session stays dead
    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups')->assertOk();
});

test('the same computer pairing with a different key is recorded as a key change', function () {
    $peer = ($this->pair)();
    $replacement = array_merge(fedPeer(InstanceTrustService::KIND), ['uuid' => $peer['uuid']]);
    [$code] = InstanceTrustService::createPairingCode(null, 'admin@example.org');

    $this->postJson(INSTANCE_API.'/pair', fedPairBody($replacement, $code))->assertCreated();

    expect(InstanceTrust::first()->public_key)->toBe($replacement['public_key'])
        ->and(InstanceAuditLog::where('action', 'instance.trust_key_changed')->exists())->toBeTrue();
    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups')->assertUnauthorized();      // the old key is out
});

test('the list shows finished zips newest first with stamp ids, and names no path', function () {
    $peer = ($this->pair)();
    ($this->zip)(ZIP_A);
    ($this->zip)('relief-instance-20260101-000000-full-manual.zip', 'older');
    ($this->zip)('relief-backup-20260201-030500-database-1.zip');   // a warehouse zip: not listed

    $res = signedCall($this, $peer, 'GET', INSTANCE_API.'/backups')->assertOk();

    expect(array_column($res->json('backups'), 'id'))->toBe([20260204030500, 20260101000000])
        ->and($res->json('backups.0'))->toMatchArray(['scope' => 'database', 'status' => 'complete', 'filename' => ZIP_A, 'size_bytes' => strlen('PK-not-really-a-zip'), 'sha256' => null])
        ->and($res->json('backups.1.scope'))->toBe('full');
});

test('show gives the whole-file hash, and a download is whole or resumed and audited once', function () {
    $peer = ($this->pair)();
    ($this->zip)(ZIP_A, 'ABCDEFGHIJ');
    $token = ($this->session)($peer);

    ($this->asToken)($token)->getJson(INSTANCE_API.'/backups/20260204030500')->assertOk()
        ->assertJsonPath('backup.sha256', hash('sha256', 'ABCDEFGHIJ'));

    ($this->asToken)($token)->get(INSTANCE_API.'/backups/20260204030500/download')->assertOk()->assertDownload(ZIP_A);
    $part = ($this->asToken)($token, ['Range' => 'bytes=4-'])->get(INSTANCE_API.'/backups/20260204030500/download');
    expect($part->getStatusCode())->toBe(206)->and($part->streamedContent())->toBe('EFGHIJ');
    expect(InstanceAuditLog::where('action', 'instance.backup_downloaded')->count())->toBe(1);   // the resumed part is the same download

    ($this->asToken)($token)->getJson(INSTANCE_API.'/backups/20990101000000')->assertNotFound();
    ($this->asToken)($token)->get(INSTANCE_API.'/backups/20990101000000/download')->assertNotFound();
    ($this->asToken)($token)->get(INSTANCE_API.'/backups/abc/download')->assertNotFound();
});

test('asking for a backup writes one fixed request, one at a time, with a scope of database or full', function () {
    $peer = ($this->pair)();

    signedCall($this, $peer, 'POST', INSTANCE_API.'/backups', json_encode(['scope' => 'storage']))->assertStatus(422);
    signedCall($this, $peer, 'POST', INSTANCE_API.'/backups', '{}')->assertStatus(422);
    expect(ProvisioningCommand::count())->toBe(ProvisioningCommand::whereNotIn('command', ['backup-now', 'backup-now-files'])->count());

    $res = signedCall($this, $peer, 'POST', INSTANCE_API.'/backups', json_encode(['scope' => 'full']))->assertStatus(202);
    $command = ProvisioningCommand::where('command', 'backup-now-files')->firstOrFail();
    expect($command->status)->toBe('pending')->and($command->warehouse_id)->toBeNull()
        ->and($command->requested_by)->toStartWith('launcher: ')
        ->and($res->json('backup'))->toMatchArray(['id' => $command->id, 'scope' => 'full', 'status' => 'queued', 'filename' => null])
        ->and(InstanceAuditLog::where('action', 'instance.backup_requested')->exists())->toBeTrue();

    $busy = signedCall($this, $peer, 'POST', INSTANCE_API.'/backups', json_encode(['scope' => 'database']))->assertStatus(409);
    expect($busy->json('backup.id'))->toBe($command->id)
        ->and(ProvisioningCommand::whereIn('command', ['backup-now', 'backup-now-files'])->count())->toBe(1);
});

test('a request is watched by its id and, once done, downloads the zip the provisioner named', function () {
    $peer = ($this->pair)();
    ($this->zip)(ZIP_A, 'ABCDEFGHIJ');
    $command = ProvisioningCommand::create(['command' => 'backup-now', 'requested_by' => 'launcher: x']);
    $token = ($this->session)($peer);
    $call = fn ($path = '') => ($this->asToken)($token)->getJson(INSTANCE_API.'/backups/'.$command->id.$path);

    $call()->assertOk()->assertJsonPath('backup.status', 'queued');
    $command->forceFill(['status' => 'running'])->save();
    $call()->assertJsonPath('backup.status', 'running')->assertJsonPath('backup.progress', 'Making the backup');
    $call('/download')->assertNotFound();                                                           // not done: nothing to download

    $command->forceFill(['status' => 'done', 'message' => 'instance backup written: /backups/instance/'.ZIP_A, 'finished_at' => now()])->save();
    $call()->assertOk()->assertJsonPath('backup.status', 'complete')->assertJsonPath('backup.filename', ZIP_A)
        ->assertJsonPath('backup.sha256', hash('sha256', 'ABCDEFGHIJ'));
    ($this->asToken)($token)->get(INSTANCE_API.'/backups/'.$command->id.'/download')->assertOk()->assertDownload(ZIP_A);

    unlink($this->tmp.'/instance/'.ZIP_A);
    $call()->assertJsonPath('backup.status', 'missing')->assertJsonPath('backup.sha256', null);

    $command->forceFill(['status' => 'failed', 'message' => 'disk full'])->save();
    $call()->assertJsonPath('backup.status', 'failed')->assertJsonPath('backup.error', 'disk full');
});

test('a provisioner answer that names anything but an instance zip is not believed', function () {
    $peer = ($this->pair)();
    ($this->zip)('notes.zip');
    $command = ProvisioningCommand::create(['command' => 'backup-now', 'requested_by' => 'x']);
    $command->forceFill(['status' => 'done', 'message' => 'wrote ../../etc/passwd and /backups/instance/notes.zip'])->save();

    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups/'.$command->id)->assertOk()->assertJsonPath('backup.status', 'missing')->assertJsonPath('backup.filename', null);
    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups/'.$command->id.'/download')->assertNotFound();
});

test('without the Docker provisioner there is nothing to ask for', function () {
    $peer = ($this->pair)();
    config(['system.runtime' => 'systemd']);

    signedCall($this, $peer, 'POST', INSTANCE_API.'/backups', json_encode(['scope' => 'database']))->assertStatus(422);
    expect(ProvisioningCommand::where('command', 'backup-now')->exists())->toBeFalse();
});

test('an instance administrator makes a code with the password, sees the computers, and revokes one', function () {
    $admin = ($this->admin)(true);
    $body = fn (array $more = []) => $more + ['password' => 'x-password-1'];

    $this->actingAs(($this->admin)(false))->postJson('/json/instance/backups/clients/code', $body())->assertForbidden();
    $this->actingAs($admin)->postJson('/json/instance/backups/clients/code', ['password' => 'wrong'])->assertStatus(422);
    expect(InstancePairingCode::count())->toBe(0);

    $made = $this->actingAs($admin)->postJson('/json/instance/backups/clients/code', $body(['name_hint' => 'Office']))->assertOk();
    expect($made->json('code'))->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/')->and($made->json('address'))->toStartWith('http')
        ->and(InstancePairingCode::first()->name_hint)->toBe('Office')->and(InstancePairingCode::first()->created_by)->toBe($admin->email);

    // the code pairs a computer, which then shows in the list
    $peer = fedPeer(InstanceTrustService::KIND);
    $this->postJson(INSTANCE_API.'/pair', fedPairBody($peer, $made->json('code')))->assertCreated();
    $list = $this->actingAs($admin)->getJson('/json/instance/backups')->assertOk();
    expect($list->json('clients.0.name'))->toBe($peer['name'])->and($list->json('clients.0.status'))->toBe('approved');

    $id = InstanceTrust::firstOrFail()->id;
    $this->actingAs($admin)->deleteJson("/json/instance/backups/clients/{$id}", ['password' => 'wrong'])->assertStatus(422);
    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups')->assertOk();
    $this->actingAs($admin)->deleteJson("/json/instance/backups/clients/{$id}", ['password' => 'x-password-1'])->assertOk();
    signedCall($this, $peer, 'GET', INSTANCE_API.'/backups')->assertUnauthorized();
    $this->actingAs($admin)->deleteJson('/json/instance/backups/clients/999999', ['password' => 'x-password-1'])->assertStatus(422);
});
