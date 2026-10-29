<?php

// Instance-level backup trust steps for the e2e, against the disposable test DB. Usage:
//   step_instance.php code        print a pairing code (Instance Admin's "Pair a computer")
//   step_instance.php provision   act as the provisioner for ONE waiting request: write a small instance zip and mark it done
//   step_instance.php revoke | audit | cleanup
require dirname(__DIR__, 2).'/print-client/e2e/bootstrap.php';
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstancePairingCode;
use App\Models\Control\InstanceTrust;
use App\Models\Control\ProvisioningCommand;
use App\Services\Federation\InstanceTrustService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// bootstrap.php only checks the default connection; this also touches the control schema, so it must be the disposable one too.
$control = DB::connection('control')->getDatabaseName();
if ($control !== 'test_reliefinventory_control') {
    fwrite(STDERR, "REFUSING: control schema is '$control', not test_reliefinventory_control\n");
    exit(99);
}

$trusts = fn () => InstanceTrust::where('name', 'like', 'RI Launcher on %')->get();
switch ($argv[1] ?? '') {
    case 'code':
        Cache::flush();   // the pairing endpoint throttles per IP in the cache
        [$code] = InstanceTrustService::createPairingCode(null, 'e2e@example.org');
        echo $code;
        break;
    case 'provision':
        $command = ProvisioningCommand::whereIn('command', ['backup-now', 'backup-now-files'])->where('status', 'pending')->orderBy('id')->first();
        if (! $command) {
            break;
        }
        $command->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $dir = rtrim(config('backup.dir'), '/').'/instance';
        @mkdir($dir, 0750, true);
        $name = 'relief-instance-'.now()->format('Ymd-His').($command->command === 'backup-now-files' ? '-full' : '-database').'-manual.zip';
        $zip = new ZipArchive;
        $zip->open("$dir/$name", ZipArchive::CREATE);
        $zip->addFromString('instance/dump.sql', "-- stand-in for the provisioner's dump\n".str_repeat('x', 4096));
        $zip->addFromString('manifest.json', json_encode(['kind' => 'instance']));
        $zip->close();
        sleep(1);   // long enough for the Launcher to see it running
        $command->forceFill(['status' => 'done', 'message' => "instance backup written: $dir/$name", 'finished_at' => now()])->save();
        break;
    case 'revoke':
        foreach ($trusts() as $t) {
            InstanceTrustService::revoke($t, 'e2e');
        }
        break;
    case 'audit':
        foreach (InstanceAuditLog::where('action', 'like', 'instance.%')->orderBy('id')->get() as $row) {
            echo $row->action, "\n";
        }
        break;
    case 'cleanup':
        foreach ($trusts() as $t) {
            $t->delete();
        }
        InstancePairingCode::where('created_by', 'e2e@example.org')->delete();
        ProvisioningCommand::whereIn('command', ['backup-now', 'backup-now-files'])->delete();
        InstanceAuditLog::where('actor', 'e2e@example.org')->orWhere('actor', 'e2e')->delete();
        echo "cleaned\n";
        break;
}
