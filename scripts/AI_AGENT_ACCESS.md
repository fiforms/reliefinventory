# AI-assisted session access: which SSH alias to use

Added 2026-09-02 alongside `FeedbackContentScanner` (see `CLAUDE.md`'s "Untrusted content /
AI-assisted changes" note) after a deliberate test — a feedback report on the demo unit tried to get
an AI assistant to exfiltrate secrets using whatever SSH access the session already had. This
document exists so an AI-assisted session reaches for the right credential by default, instead of
always using the account with the broadest access out of habit.

There are two SSH aliases into each server (configured locally in the operator's `~/.ssh/config`, not
in this repo):

## `demolinode` — root, full access

Logs straight in as `root`, passwordless. This is the account `scripts/update.sh` expects to be run
as, and the one with access to everything: `.env`, the backup tiers under `/var/backups/`
(DB dumps, `.env` copies, `storage/app`), system package management, service configuration, other
users' files.

**Use this only when the task genuinely needs it** — running the full `scripts/update.sh` (which
includes a pre-update backup), reading/restoring backups, one-time provisioning (installing system
packages, setting up a systemd timer, the browsershot/weasyprint dependencies in `CLAUDE.md`),
debugging that requires broad read access — and only when the user is actively directing the session,
not from an unattended/scheduled run.

## `demolinode-agent` — routine deploys only, both instances

Demo and wa26 are the same physical box, so one account services both. A dedicated, unprivileged
system account (`claude-agent`) with its own SSH keypair, no `sudo` group membership, no `www-data`
group membership, and exactly two narrowly-scoped sudo grants (`/etc/sudoers.d/claude-agent`):

```
claude-agent ALL=(root) NOPASSWD: /usr/local/sbin/reliefinventory-agent-deploy.sh ""
claude-agent ALL=(root) NOPASSWD: /usr/local/sbin/reliefinventory-agent-deploy-wa26.sh ""
```

**The trailing `""` matters** — an unqualified command path in a sudoers rule permits *any*
arguments, not just none; only an explicit empty-string argument restricts it to zero. Found this
live: the first version of this rule (no trailing `""`) let an arbitrary extra argument through,
which was harmless in itself but meant the "no parameters, ever" guarantee didn't actually hold.
Always include the `""` when adding a rule like this.

Both wrapper scripts live **outside this git repo**, owned `root:root`, mode `0700` — deliberately
not editable by anything this account (or a session using it) can reach, including by pushing a
commit. Each runs a trimmed version of `scripts/update.sh`'s core sequence, hardcoded per instance
(no shared parameterized script, so there's nothing to pass an instance name into): `git fetch` +
`reset --hard` to `origin/master` (fixed branch), conditional `composer install`/`npm ci`,
`npm run build`, `artisan migrate --force`, cache rebuild, restart that instance's queue worker +
reload php-fpm, health check against that instance's own URL. Neither dumps the database, touches
`.env`, writes under `/var/backups/`, or runs seeders — those stay deliberate, root-account actions
(`PermissionsSeeder` in particular: safe to repeat, per `update.sh`'s own comment, but still run
manually as root when needed, not wired into the agent path). Backup coverage between deploys is
unaffected: the existing `reliefinventory-backup.timer` / `reliefinventory-wa26-backup.timer` (see
`BACKUPS.md`) run independently of any deploy and aren't touched by this account.

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
