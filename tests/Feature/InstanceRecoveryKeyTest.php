<?php

// The instance recovery key: the public half is kept, the private half is shown once and kept nowhere. Service, Instance Admin endpoints,
// the banner for instance admins, and the `instance:recovery-key` command. (Sealing into a zip and restoring from it: InstanceBackupTest.)

use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstanceRecoveryKey;
use App\Services\InstanceRecoveryKeys;
use App\Support\RecoveryKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['system.runtime' => 'docker', 'backup.recovery_public_key' => null]);
    InstanceRecoveryKey::query()->delete();
    $this->accounts = [];
    $this->admin = function (bool $instanceAdmin = true) {
        $user = userWithPermissions('admin-system');
        $account = new Account(['email' => 'rk-'.Str::random(6).'@example.org']);
        $account->forceFill(['password' => Hash::make('x-password-1'), 'email_verified_at' => now(), 'is_instance_admin' => $instanceAdmin])->save();
        $user->forceFill(['account_id' => $account->id])->save();
        $this->accounts[] = $account;

        return $user->fresh();
    };
});

afterEach(function () {
    InstanceRecoveryKey::query()->delete();
    foreach ($this->accounts as $account) {
        $account->delete();
    }
});

/** Everything the control schema and the audit trail hold, as one string, to look for a secret in. */
function everythingStored(): string
{
    return json_encode(DB::connection('control')->table('instance_recovery_key')->get()).json_encode(InstanceAuditLog::all()->toArray());
}

test('generating keeps the public half only: the private key is in neither the table nor the audit log', function () {
    $keys = app(InstanceRecoveryKeys::class);
    expect($keys->state())->toBe('none')->and($keys->publicKey())->toBeNull();

    $pair = $keys->generate('admin@example.org');

    $row = InstanceRecoveryKey::find(1);
    expect($pair['private'])->toStartWith('RK1-')
        ->and($row->public_key)->toBe($pair['public'])->and($row->fingerprint)->toBe($pair['fingerprint'])
        ->and($row->source)->toBe('generated')->and($row->created_by)->toBe('admin@example.org')
        ->and($keys->publicKey())->toBe($pair['public'])
        ->and(everythingStored())->not->toContain($pair['private'])->not->toContain(substr($pair['private'], 4, 20))
        ->and(InstanceAuditLog::where('action', 'instance.recovery_key_created')->first()->details['fingerprint'] ?? null)->toBe($pair['fingerprint']);
});

test('a generated key is unconfirmed until the administrator says it is stored', function () {
    $keys = app(InstanceRecoveryKeys::class);
    $keys->generate('admin@example.org');

    expect($keys->state())->toBe('unconfirmed')->and($keys->payload()['acknowledged_at'])->toBeNull();

    $keys->acknowledge('admin@example.org');
    expect($keys->state())->toBe('ok')->and(InstanceAuditLog::where('action', 'instance.recovery_key_confirmed')->count())->toBe(1);

    $keys->acknowledge('admin@example.org');   // again: nothing more to record
    expect(InstanceAuditLog::where('action', 'instance.recovery_key_confirmed')->count())->toBe(1)
        ->and(fn () => (InstanceRecoveryKey::query()->delete() | 1) && $keys->acknowledge('x'))->toThrow(RuntimeException::class, 'no recovery key');
});

test('a second key needs replace to be said, and the old fingerprint is recorded', function () {
    $keys = app(InstanceRecoveryKeys::class);
    $first = $keys->generate('admin@example.org');
    $keys->acknowledge('admin@example.org');

    expect(fn () => $keys->generate('admin@example.org'))->toThrow(RuntimeException::class, 'already has a recovery key');
    expect($keys->publicKey())->toBe($first['public']);

    $second = $keys->generate('admin@example.org', true);
    expect($keys->publicKey())->toBe($second['public'])->not->toBe($first['public'])
        ->and($keys->state())->toBe('unconfirmed')   // a new key has to be confirmed again
        ->and(InstanceAuditLog::where('action', 'instance.recovery_key_replaced')->first()->details['replaced'])->toBe($first['fingerprint']);
});

test('a public key made elsewhere is adopted as confirmed, and only if it is well formed', function () {
    $keys = app(InstanceRecoveryKeys::class);
    $pair = RecoveryKey::generate();

    expect(fn () => $keys->adopt('nonsense', 'installer'))->toThrow(RuntimeException::class, 'not a recovery public key')
        ->and(fn () => $keys->adopt($pair['private'], 'installer'))->toThrow(RuntimeException::class);

    $row = $keys->adopt($pair['public'], 'installer');
    expect($row->source)->toBe('adopted')->and($keys->state())->toBe('ok')->and($row->fingerprint)->toBe($pair['fingerprint']);

    $other = RecoveryKey::generate();
    expect(fn () => $keys->adopt($other['public'], 'installer'))->toThrow(RuntimeException::class, 'already has')
        ->and($keys->adopt($pair['public'], 'installer')->fingerprint)->toBe($pair['fingerprint'])   // the same key again is fine
        ->and($keys->adopt($other['public'], 'installer', true)->fingerprint)->toBe($other['fingerprint']);
});

test('the environment can hand over the public key of a pair made by the installer, once, and never overrides a key already held', function () {
    $pair = RecoveryKey::generate();
    config(['backup.recovery_public_key' => $pair['public']]);
    $keys = app(InstanceRecoveryKeys::class);

    expect($keys->state())->toBe('ok')->and($keys->payload()['source'])->toBe('environment')
        ->and(InstanceRecoveryKey::find(1)->public_key)->toBe($pair['public']);

    $other = RecoveryKey::generate();
    config(['backup.recovery_public_key' => $other['public']]);
    expect($keys->publicKey())->toBe($pair['public']);   // the table wins

    InstanceRecoveryKey::query()->delete();
    config(['backup.recovery_public_key' => 'garbage']);
    expect($keys->state())->toBe('none');                 // a bad value is ignored, not trusted
});

test('only an instance administrator can make the key, with their password, and the private key comes back once', function () {
    $admin = ($this->admin)(true);

    $this->postJson('/json/instance/backups/recovery-key', ['password' => 'x-password-1'])->assertUnauthorized();
    $this->actingAs(($this->admin)(false))->postJson('/json/instance/backups/recovery-key', ['password' => 'x-password-1'])->assertForbidden();
    $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key', ['password' => 'wrong'])->assertStatus(422);
    expect(InstanceRecoveryKey::count())->toBe(0);

    $res = $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key', ['password' => 'x-password-1'])->assertOk();
    $private = $res->json('private_key');
    expect($private)->toStartWith('RK1-')
        ->and($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->json('fingerprint'))->toBe(InstanceRecoveryKey::find(1)->fingerprint)
        ->and($res->json('recovery_key.state'))->toBe('unconfirmed')
        ->and(everythingStored())->not->toContain($private);

    // never again: the list and every later payload hold no secret
    $again = $this->actingAs($admin)->getJson('/json/instance/backups')->assertOk();
    expect($again->getContent())->not->toContain($private)->not->toContain('private_key')
        ->and($again->json('recovery_key.state'))->toBe('unconfirmed');
});

test('replacing an existing key must be asked for, and confirming needs no password but an instance administrator', function () {
    $admin = ($this->admin)(true);
    $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key', ['password' => 'x-password-1'])->assertOk();

    $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key', ['password' => 'x-password-1'])->assertStatus(422);
    $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key', ['password' => 'x-password-1', 'replace' => true])->assertOk();

    $this->actingAs(($this->admin)(false))->postJson('/json/instance/backups/recovery-key/confirm')->assertForbidden();
    $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key/confirm')->assertOk()->assertJsonPath('recovery_key.state', 'ok');
    InstanceRecoveryKey::query()->delete();
    $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key/confirm')->assertStatus(422);
});

test('without the Docker provisioner there is nothing to seal and no key to make', function () {
    config(['system.runtime' => 'systemd']);
    $admin = ($this->admin)(true);

    $this->actingAs($admin)->postJson('/json/instance/backups/recovery-key', ['password' => 'x-password-1'])->assertStatus(422);
    expect(InstanceRecoveryKey::count())->toBe(0);
});

test('instance administrators are reminded on every page until the key is made and confirmed, and nobody else is', function () {
    $admin = ($this->admin)(true);
    $page = fn ($user) => $this->actingAs($user)->get('/setup/system');

    $this->actingAs($admin)->get('/setup/instance')->assertInertia(fn ($p) => $p->where('recoveryKey.state', 'none'));
    $this->actingAs(($this->admin)(false))->get('/setup/system')->assertInertia(fn ($p) => $p->where('recoveryKey', null));

    app(InstanceRecoveryKeys::class)->generate('admin@example.org');
    $this->actingAs($admin)->get('/setup/instance')->assertInertia(fn ($p) => $p->where('recoveryKey.state', 'unconfirmed'));

    app(InstanceRecoveryKeys::class)->acknowledge('admin@example.org');
    $this->actingAs($admin)->get('/setup/instance')->assertInertia(fn ($p) => $p->where('recoveryKey', null));

    // a bare-metal install has no instance backups, so nothing to nag about
    InstanceRecoveryKey::query()->delete();
    config(['system.runtime' => 'systemd']);
    $this->actingAs($admin)->get('/setup/instance')->assertInertia(fn ($p) => $p->where('recoveryKey', null));
});

test('the command makes a key, shows it once with what to do, and the confirmation is asked for', function () {
    $this->artisan('instance:recovery-key', ['action' => 'status'])->assertSuccessful()->expectsOutputToContain('No recovery key');

    $this->artisan('instance:recovery-key', ['action' => 'generate'])
        ->expectsOutputToContain('PRINT THIS AND STORE IT SAFELY')
        ->expectsOutputToContain('away from this server and away from your backups')
        ->expectsOutputToContain('ONLY NOW')
        ->expectsConfirmation('Have you printed it or saved it somewhere safe, away from this server and your backups?', 'yes')
        ->assertSuccessful();
    expect(app(InstanceRecoveryKeys::class)->state())->toBe('ok');

    $this->artisan('instance:recovery-key', ['action' => 'generate'])->assertFailed();   // one exists: --replace to change it
    $this->artisan('instance:recovery-key', ['action' => 'status'])->assertSuccessful()->expectsOutputToContain(InstanceRecoveryKey::find(1)->fingerprint);
});

test('a key shown but not confirmed on the command line stays unconfirmed, and the installer\'s json form leaves confirming to its caller', function () {
    $this->artisan('instance:recovery-key', ['action' => 'generate', '--no-interaction' => true])->expectsOutputToContain('Not confirmed')->assertSuccessful();
    expect(app(InstanceRecoveryKeys::class)->state())->toBe('unconfirmed');

    $this->artisan('instance:recovery-key', ['action' => 'acknowledge'])->assertSuccessful();
    expect(app(InstanceRecoveryKeys::class)->state())->toBe('ok');

    InstanceRecoveryKey::query()->delete();
    $this->artisan('instance:recovery-key', ['action' => 'generate', '--json' => true])->assertSuccessful();
    $row = InstanceRecoveryKey::find(1);
    expect($row)->not->toBeNull()->and($row->acknowledged_at)->toBeNull();

    // the JSON carries the private key (for a person to see) and matches what the instance now holds
    Artisan::call('instance:recovery-key', ['action' => 'generate', '--json' => true, '--replace' => true]);
    $json = json_decode(Artisan::output(), true);
    expect(RecoveryKey::publicFor(RecoveryKey::parse($json['private_key'])))->toBe(InstanceRecoveryKey::find(1)->public_key)
        ->and($json['public_key'])->toBe(InstanceRecoveryKey::find(1)->public_key)
        ->and(everythingStored())->not->toContain($json['private_key']);
});

test('keypair makes a pair and stores nothing; adopt takes its public half; verify checks a printout', function () {
    Artisan::call('instance:recovery-key', ['action' => 'keypair', '--json' => true]);
    $pair = json_decode(Artisan::output(), true);
    expect(InstanceRecoveryKey::count())->toBe(0);

    $this->artisan('instance:recovery-key', ['action' => 'adopt', '--public-key' => $pair['public_key']])->assertSuccessful();
    expect(app(InstanceRecoveryKeys::class)->state())->toBe('ok');
    $this->artisan('instance:recovery-key', ['action' => 'adopt', '--public-key' => 'junk'])->assertFailed();

    $file = tempnam(sys_get_temp_dir(), 'rk');
    try {
        file_put_contents($file, "Recovery key:\n  {$pair['private_key']}\n");
        $this->artisan('instance:recovery-key', ['action' => 'verify', '--key-file' => $file])->expectsOutputToContain('matches')->assertSuccessful();

        $typo = $pair['private_key'];
        $typo[6] = $typo[6] === 'A' ? 'B' : 'A';
        file_put_contents($file, $typo);
        $this->artisan('instance:recovery-key', ['action' => 'verify', '--key-file' => $file])->expectsOutputToContain('typing mistake')->assertFailed();

        $stranger = RecoveryKey::generate();
        file_put_contents($file, $stranger['private']);
        $this->artisan('instance:recovery-key', ['action' => 'verify', '--key-file' => $file])->expectsOutputToContain("not this instance's")->assertFailed();
    } finally {
        @unlink($file);
    }
});

test('instance:status says where the recovery key stands', function () {
    $this->artisan('instance:status')->expectsOutputToContain('Recovery key: none')->assertSuccessful();

    app(InstanceRecoveryKeys::class)->generate('admin@example.org');
    $this->artisan('instance:status')->expectsOutputToContain('nobody confirmed')->assertSuccessful();

    app(InstanceRecoveryKeys::class)->acknowledge('admin@example.org');
    $this->artisan('instance:status')->expectsOutputToContain(InstanceRecoveryKey::find(1)->fingerprint)->assertSuccessful();
});
