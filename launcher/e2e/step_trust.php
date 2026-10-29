<?php

// Acts on the e2e launcher's trust(s) in the test DB. Usage: step_trust.php revoke | grant | audit | cleanup
require dirname(__DIR__, 2).'/print-client/e2e/bootstrap.php';
use App\Models\Backup;
use App\Models\Trust;
use App\Models\TrustAuditLog;
use App\Services\Federation\TrustService;

$trusts = Trust::where('kind', 'backup_client')->where('name', 'like', 'RI Launcher on %')->get();
switch ($argv[1] ?? '') {
    case 'revoke':
        foreach ($trusts as $t) {
            TrustService::revoke($t, null);
        }
        echo "revoked {$trusts->count()}\n";
        break;
    case 'grant':
        foreach ($trusts as $t) {
            TrustService::setInboundGrants($t, ['operator.full_read'], null);
        }
        break;
    case 'audit':
        foreach (TrustAuditLog::whereIn('trust_id', $trusts->pluck('id'))->orderBy('id')->get() as $row) {
            echo $row->event, ' ', json_encode($row->detail), "\n";
        }
        break;
    case 'cleanup':
        TrustAuditLog::whereIn('trust_id', $trusts->pluck('id'))->delete();
        foreach ($trusts as $t) {
            $t->delete();
        }
        foreach (Backup::all() as $b) {
            @unlink($b->path());
            $b->delete();
        }
        echo "cleaned\n";
        break;
}
