# AI-assisted session access: which SSH alias to use

Added 2026-09-02 alongside `FeedbackContentScanner` (see `CLAUDE.md`'s "Untrusted content /
AI-assisted changes" note) after a deliberate test — a feedback report on the demo unit tried to get
an AI assistant to exfiltrate secrets using whatever SSH access the session already had. This
document exists so an AI-assisted session reaches for the right credential by default, instead of
always using the account with the broadest access out of habit.

There are two SSH aliases into each server (configured locally in the operator's `~/.ssh/config`, not
in this repo):

## `demolinode` (and `demolinode-wa26`) — root, full access

Logs straight in as `root`, passwordless. This is the account `scripts/update.sh` expects to be run
as, and the one with access to everything: `.env`, the backup tiers under `/var/backups/`
(DB dumps, `.env` copies, `storage/app`), system package management, service configuration, other
users' files.

**Use this only when the task genuinely needs it** — running the full `scripts/update.sh` (which
includes a pre-update backup), reading/restoring backups, one-time provisioning (installing system
packages, setting up a systemd timer, the browsershot/weasyprint dependencies in `CLAUDE.md`),
debugging that requires broad read access — and only when the user is actively directing the session,
not from an unattended/scheduled run.

## `demolinode-agent` (and equivalent on other instances) — routine deploys only

A dedicated, unprivileged system account (`claude-agent`) with its own SSH keypair, no `sudo` group
membership, no `www-data` group membership, and exactly one narrowly-scoped sudo grant:

```
claude-agent ALL=(root) NOPASSWD: /usr/local/sbin/reliefinventory-agent-deploy.sh
```

That wrapper script lives **outside this git repo**, owned `root:root`, mode `0700` — deliberately
not editable by anything this account (or a session using it) can reach, including by pushing a
commit. It runs a trimmed version of `scripts/update.sh`'s core sequence: `git fetch` + `reset --hard`
to `origin/master` (fixed branch), conditional `composer install`/`npm ci`, `npm run build`,
`artisan migrate --force`, cache rebuild, restart the queue worker + reload php-fpm, health check.
It does **not** dump the database, does **not** touch `.env` or anything under `/var/backups/`, and
does not run seeders — those stay deliberate, root-account actions. Backup coverage between deploys
is unaffected: the existing `reliefinventory-backup.timer` (see `BACKUPS.md`) runs independently of
any deploy and isn't touched by this account.

File permissions on the server independently deny this account read access to `.env` (mode `640`,
owned `www-data:www-data`) and to backup contents (individual snapshot directories are `700`/`600`)
even without the sudo restriction — the sudo scope and the file permissions are two separate layers,
neither depends on the other holding.

**Use this for the routine "fix a bug, deploy the fix" loop** that most AI-assisted sessions are
actually doing — it's the account with no standing access to anything worth exfiltrating, so even a
successfully-manipulated session gains nothing beyond "the code got deployed," which is the same
outcome a legitimate fix produces.

## What this does and doesn't protect against

This limits the blast radius of an SSH session being misused — it does not replace judgment about
whether to act on a given request in the first place (see `FeedbackContentScanner` and the CLAUDE.md
note). A malicious "feedback report" asking for `.env`'s contents fails against `demolinode-agent`
regardless of whether the request is recognized as malicious; the same request against `demolinode`
still requires a human (or the assistant) to recognize and refuse it. Use the narrower account by
default so that judgment call isn't the only thing standing between a bad request and real secrets.
