# 0002 — Feedback email digest and a Developer role

- **Status:** Under discussion
- **Lane:** Shared (permission model + notifications). **How the preferences are stored is Daniel's call.**
- **Author:** Mark
- **Idea:** none in ROADMAP.md; raised directly by Mark on 2026-10-26 while triaging demo's feedback queue

## Problem

**Feedback emails arrive one at a time, for everything.** `FeedbackReportController::notifyDevelopers()` sends a
`FeedbackReportSubmitted` email to every address on Email Settings' recipient list the moment a report is saved. During
a class or a busy day that's a flood: on 2026-10-24 alone, cs26 sent 17 reports in about six hours. Most of them aren't
urgent. The few that are (two were marked urgent that day) are lost in the pile.

Two more gaps:

- **Reports that arrive through aggregation send no email at all.** Central ingests them
  (`FeedbackIngestController::storeFromTrust`) but never notifies anyone. Demo now receives cs26's reports this way, so
  the instance with the most reports is the one that stays quiet.
- **Who gets the email isn't tied to anyone's account.** The recipient list is a comma-separated field on Email Settings.
  That was deliberate, since developers weren't necessarily `admin-system` holders. But it means a developer can't
  choose how they're notified, and nobody can see from the app who receives what.

**There's no account level for a developer.** Today the top level is Administrator, which automatically gets every
permission. Mark wants a level **one step above Administrator**: a Developer holds everything an Administrator does,
plus things meant only for the people building and supporting the software. Feedback notifications come first; remote
support (proposal 0003) would be another.

## Proposed direction

### Developer role

- A new role, **Developer**, that holds every permission.
- **Administrator holds every permission except a short "developer-only" list.** Today Administrator gets "every key"
  automatically, so this list is the one exception to that.
- Permissions keep being named for what they do, not who holds them. The first developer-only permission is **receive
  feedback notifications**. Remote support (0003) would add **provide remote support**. Don't add a permission until
  something uses it.
- **Granting Developer.** Only someone who already holds every Developer permission can grant it. The existing escalation
  rule (`PeopleController::assertNoEscalation()`) already gives that result: an Administrator can't grant permissions
  they don't hold. The first Developer on an instance is therefore made from the command line, by extending
  `user:promote` to take a role. Doing it from the command line is intentional, not a gap: an Administrator can't promote
  themselves.
- On User Administration, Developer appears above Administrator as one of the existing role presets.
- **No instance ships with a Developer.** The first-run wizard still creates an Administrator. Developers are added only
  on instances whose operators want us notified or able to support.

### Digest, set per developer

- **What goes out immediately:** a report marked **Urgent**, or one the content scanner flagged for review
  (`flagged_for_review`). Everything else waits for the digest. On the report form, the checkbox becomes "Urgent /
  blocking my work — notifies the developers right away", so reporters know what it does.
- **Each developer chooses** one of:
  - **Immediately:** every report, as today.
  - **Daily digest at a chosen hour:** urgent reports still arrive immediately.
  - **Off.**
  The default for a new Developer is a daily digest at 7:00.
- **Where the choice is made:** a "Feedback notifications" section on the developer's own Profile page, shown only to
  people with the permission. A developer changes only their own choice. `/setup/feedback` shows a read-only list of who
  receives what.
- **What the digest contains:**
  - Every report received since that developer's last digest: instance, type, page, the start of the message, and a
    link.
  - Status changes and notes on reports in the same period.
  - Nothing is sent when there's nothing new. A digest is never sent twice for the same period, even if the job runs
    twice.
- **Aggregated reports count the same.** A report that arrives at central over a trust goes through the same rule. It is
  notified when it's first received, never again on a re-push of the same report.
- **The comma-separated list stays**, relabelled "Also email these addresses immediately", for addresses that have no
  account on the instance, such as a remote instance that emails a developer directly instead of aggregating. It keeps
  today's behaviour, so nothing changes for an instance that doesn't add a Developer.

### Running the digest

The app has no Laravel scheduler. Hourly jobs run from `docker/scheduler.sh` (Docker, R Station, Launcher) and from
systemd timers on bare metal (`scripts/systemd/`). The digest would be one hourly artisan command added to both,
following the volunteer-sync timer. On bare metal, the timer needs a one-time install on each server, like the
volunteer-sync timer; `update.sh` could install it if it's missing.

## Rejected alternatives

- **Digest on the shared recipient list, with no accounts involved.** Simpler, but it leaves the choice instance-wide,
  not per developer, which is the point of the request.
- **Using the in-app bell instead of email.** The bell (`notifications` table) exists, but developers aren't in the app
  all day. The bell could carry the same notices later; email is the requirement now.
- **Making Developer a permission override on an Administrator instead of a role.** A role is how every other level is
  expressed, and it shows in User Administration as one tap.
- **Leaving Administrator with every key and giving developers an extra flag.** A flag outside the permission model
  would bypass `CheckPermission`, the menu and the escalation rule, all of which already work for permissions.

## Open questions

- **Storage (Daniel).** Each developer needs a stored choice (mode and hour) and a "last digest sent" time so the digest
  is exactly-once. This could be a small table keyed by person, columns on `people`, or something more general that a
  later notification preference could reuse (the bell, partner emails). Which?
- **Status-change emails to reporters** (`FeedbackReportStatusUpdated`) are separate and unchanged here. Should
  reporters also get a digest option later?
- **Timezone.** The hour is in the instance's timezone. Is that right for a developer who receives digests from several
  instances? (With aggregation, they'd normally be a Developer only on central.)
- **What else is developer-only.** The slow-query log and Testing Tools are `admin-system` today and useful to local
  admins too. Propose leaving them where they are unless there's a reason to move them.

## What it touches

- Permission model: a new role, a new permission, Administrator's "every key" rule (`PermissionsSeeder`, plus a
  migration following the Training Mode role's), `user:promote`.
- `FeedbackReportController` and `FeedbackIngestController` (one shared notifier), a new digest email, an hourly command
  in `docker/scheduler.sh`, and a systemd timer.
- Screens: Profile (notification choice), `/setup/feedback` (who receives what), `/setup/email` (the list relabelled),
  `FeedbackReportModal.vue` (the Urgent wording), and User Administration (the Developer preset).
- Related: proposal 0003 (remote support), which would add the second developer-only permission; feedback report #32
  (assigning a report to a developer), which becomes simple once developers are accounts.
