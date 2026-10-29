<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\InstanceRecoveryKeys;
use App\Support\RecoveryKey;
use Illuminate\Console\Command;
use Throwable;

/**
 * The instance's recovery key from a shell (scripts/BACKUPS.md "Recovery key"): whole-instance backups seal every warehouse's key to its public
 * half, so an automatic backup can be restored, keys and all, by whoever holds the private half on paper.
 *
 *   status        what the instance has (never anything secret)
 *   generate      make a pair, keep the public half, SHOW THE PRIVATE HALF ONCE (--replace to change an existing one; --json for an installer/launcher)
 *   keypair       make a pair and store nothing: for an installer that keeps the private half and gives the instance only the public one
 *   adopt         take a public key made elsewhere (--public-key=…, or RI_RECOVERY_PUBLIC_KEY)
 *   acknowledge   say the private key is stored (what `generate --json` leaves to its caller, after it has shown the key to a person)
 *   verify        check a printed key against the one the instance holds, so a bad printout is found now, not in a disaster
 */
class InstanceRecoveryKeyCommand extends Command
{
    protected $signature = 'instance:recovery-key
        {action=status : status | generate | keypair | adopt | acknowledge | verify}
        {--public-key= : adopt: the public key (43 characters)}
        {--replace : generate/adopt: replace the key the instance already has}
        {--json : generate/keypair/status: print JSON (the private key is in it: show it to a person, never log it)}
        {--key-file= : verify: a file holding the printed key (otherwise asked for)}';

    protected $description = "The instance's backup recovery key: make it, check a printout of it, see its state";

    public function handle(InstanceRecoveryKeys $keys): int
    {
        try {
            return match ($this->argument('action')) {
                'status' => $this->status($keys),
                'generate' => $this->generate($keys),
                'keypair' => $this->keypair(),
                'adopt' => $this->adopt($keys),
                'acknowledge' => $this->acknowledge($keys),
                'verify' => $this->verify($keys),
                default => $this->fail('Unknown action. Use status, generate, keypair, adopt, acknowledge or verify.'),
            };
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function status(InstanceRecoveryKeys $keys): int
    {
        $payload = $keys->payload();
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        match ($payload['state']) {
            'none' => $this->components->warn('No recovery key. Automatic backups cannot restore the warehouses\' encrypted settings. `php artisan instance:recovery-key generate`, or Instance Admin > Full Instance Backups.'),
            'unconfirmed' => $this->components->warn("Recovery key {$payload['fingerprint']} was made but nobody confirmed storing the private half. If it was not saved, make a new one (`generate --replace`)."),
            default => $this->line("Recovery key {$payload['fingerprint']} ({$payload['source']}, by {$payload['created_by']}, {$payload['created_at']}). Every whole-instance backup is sealed to it."),
        };

        return self::SUCCESS;
    }

    private function generate(InstanceRecoveryKeys $keys): int
    {
        $pair = $keys->generate('command line', (bool) $this->option('replace'));
        if ($this->option('json')) {
            // The caller (the RI Launcher, an installer) shows this to a person and then runs `acknowledge`.
            $this->line(json_encode(['private_key' => $pair['private'], 'public_key' => $pair['public'], 'fingerprint' => $pair['fingerprint']], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->showOnce($pair['private'], $pair['fingerprint']);
        if ($this->input->isInteractive() && $this->confirm('Have you printed it or saved it somewhere safe, away from this server and your backups?', false)) {
            $keys->acknowledge('command line');
            $this->components->info('Confirmed.');
        } else {
            $this->components->warn('Not confirmed. The instance will keep reminding you until `php artisan instance:recovery-key acknowledge` (or the button on Instance Admin).');
        }

        return self::SUCCESS;
    }

    private function keypair(): int
    {
        $pair = RecoveryKey::generate();
        if ($this->option('json')) {
            $this->line(json_encode(['private_key' => $pair['private'], 'public_key' => $pair['public'], 'fingerprint' => $pair['fingerprint']], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->showOnce($pair['private'], $pair['fingerprint']);
        $this->line('Nothing was stored. Give the instance the public half:');
        $this->line("  RI_RECOVERY_PUBLIC_KEY={$pair['public']}      (in its environment), or");
        $this->line("  php artisan instance:recovery-key adopt --public-key={$pair['public']}");

        return self::SUCCESS;
    }

    private function adopt(InstanceRecoveryKeys $keys): int
    {
        $public = (string) ($this->option('public-key') ?: config('backup.recovery_public_key'));
        $row = $keys->adopt($public, 'command line', (bool) $this->option('replace'));
        $this->components->info("Recovery public key {$row->fingerprint} adopted. Whole-instance backups are sealed to it from now on.");

        return self::SUCCESS;
    }

    private function acknowledge(InstanceRecoveryKeys $keys): int
    {
        $keys->acknowledge('command line');
        $this->components->info('Confirmed: the private key is stored.');

        return self::SUCCESS;
    }

    private function verify(InstanceRecoveryKeys $keys): int
    {
        $row = $keys->row() ?? throw new \RuntimeException('This instance has no recovery key.');
        if ($file = $this->option('key-file')) {
            $text = is_readable($file) ? (string) file_get_contents($file) : throw new \RuntimeException("{$file} is not readable.");
        } elseif ($this->input->isInteractive()) {
            $text = (string) $this->secret('Type the key from your printout (RK1-…)');
        } else {
            throw new \RuntimeException('Give --key-file, or run this in a terminal.');
        }
        $secret = RecoveryKey::parse($text);   // a typing mistake says so
        if (RecoveryKey::fingerprint(RecoveryKey::publicFor($secret)) !== $row->fingerprint) {
            $this->components->error("That is a valid key, but it is not this instance's (it holds {$row->fingerprint}).");

            return self::FAILURE;
        }
        $this->components->info("Your printout matches this instance's recovery key {$row->fingerprint}.");

        return self::SUCCESS;
    }

    /** The one place the private key is put on a screen. */
    private function showOnce(string $private, string $fingerprint): void
    {
        $this->newLine();
        $this->line('  <options=bold>RECOVERY KEY — PRINT THIS AND STORE IT SAFELY</>');
        $this->newLine();
        $this->line("  Fingerprint:  {$fingerprint}");
        $this->line("  Key:          <options=bold>{$private}</>");
        $this->newLine();
        $this->line('  Please print this key and store it somewhere safe, away from this server and away from your backups (a safe, a sealed envelope,');
        $this->line('  a password manager). It is shown ONLY NOW and cannot be shown again: the server does not keep it.');
        $this->line('  After a disaster it is the one thing that lets an automatic backup restore every warehouse\'s encrypted settings.');
        $this->line('  Anyone who has both this key and a backup zip can read those settings, so do not keep them together.');
        $this->newLine();
    }
}
