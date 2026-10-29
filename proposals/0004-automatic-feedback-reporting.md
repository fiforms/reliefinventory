# 0004 — Automatic feedback reporting to a central instance

- **Status:** Under discussion
- **Lane:** Shared (feedback aggregation). No schema change, so nothing for the data-model lane to decide.
- **Author:** Mark (original); simplified by Daniel
- **Idea:** none in ROADMAP.md; raised directly by Mark on 2026-10-26. The sending-side companion to proposal 0002
  (digest on the receiving side).

## Problem

An instance linked to a central instance (Feedback page > Send reports to) sends a report **once, at the moment it's
saved**, and only when Offline Mode is off (`FeedbackPushService::pushOne`). If that one attempt fails, the report sits
unsent until someone opens `/setup/feedback` and presses **Send Unsent Reports**. Nothing retries it, and nobody is told.

That's a poor fit for where this software runs. A field site's connection comes and goes, so the reports that matter
most are the likeliest to be lost until someone remembers the button.

## Proposed direction

**Keep trying, backing off between attempts, and show the state on the Feedback page.** No new settings, no new tables.

- **Automatic means what it already means.** `FeedbackPushService::autoPushEnabled()` is true when a central instance is
  linked and Offline Mode is off. That is the switch. There is no separate On/Off setting.
- **One periodic command**, `feedback:push-unsent`, runs every few minutes. If automatic reporting is enabled and any
  report is unsent, it calls the existing `pushUnsent()`. Otherwise it exits without touching the network. A report is
  unsent when `submitted_at` and `instance_identifier` are both null, which is already how `pushUnsent()` finds them.
- **Backoff lives in the cache.** One key holds "don't try before T". A failure doubles the wait (start about 5 minutes,
  cap about 1 hour); a success deletes the key. A cache flush just causes one early retry. Saving a new report still
  attempts at once, as today, and respects the same key.
- **Offline Mode still wins.** While it's on, nothing is sent automatically. **Send Unsent Reports** still works, as today,
  ignores the backoff, and clears it.
- **The Feedback page shows the state, computed when it's loaded:** how many reports are waiting, the age of the oldest,
  the last error in plain words (the existing `pushUnsent()['error']` messages: revoked, not granted, clock off, couldn't
  reach it) and when sending last succeeded. The notice turns red when the oldest waiting report is more than a day old.
  Reports the central instance refuses as malformed are counted separately ("N not accepted by the other instance"), so
  that isn't mistaken for a connection problem.
- **A permanent failure** (the link was revoked, or sending isn't granted) retries at the capped rate, about one small
  request an hour, and the page says so in the error. Waiting won't fix it and the page makes that clear.
- **Every report is sent as soon as it can be.** No urgent/non-urgent split and no daily mode. Reducing email noise is
  proposal 0002's job on the receiving side.

The periodic command goes in `docker/scheduler.sh` and a systemd timer, like `volunteers:sync-active-windows`. If the
shared hourly entry point suggested for proposal 0002 is built first, this can hang off it, at the cost of slower recovery.

## Rejected alternatives

### Mark's original design

Mark's first version had these parts:

- an **Automatic reporting On/Off** setting;
- **urgent reports sent immediately and always**, and everything else either immediately or **daily at a chosen hour**;
- a rule that **three failed attempts in a row turn automatic reporting off**, whatever the cause;
- an **alert** raised at that moment, shown in the bell and as a red notice, which **stays until an administrator
  acknowledges it**, recording who and when;
- re-enabling as a separate, deliberate step that resets the failure count.

Mark chose the three-strikes rule on purpose: it's easy to explain and makes a person look every time. The cost was
written into the proposal: an outage alone turns reporting off, so at a patchy site urgent reports wait until someone
acknowledges the alert and switches it back on.

**Why that is more than the problem needs:**

- **It adds state that has to be kept consistent.** The mode setting, the failure count, the last error and the
  alert with its acknowledger needed a home, which is a new table or several new columns. Each one is a way to end up
  with a count that disagrees with reality, such as an alert that never clears or reporting stuck Off after the cause is gone.
- **It fails the case it was written for.** The point is that reports from a flaky field site get through. A rule that
  switches sending off after three tries is switched off by the first outage, which is the thing it needed to survive.
  Retrying with a growing wait handles the outage with no one's help.
- **The daily mode and the urgent split solve the wrong problem.** Batching on the sending side was meant to cut email
  noise, but email is sent by the receiving side, and 0002 gives that a digest and an urgent path. Sending a report to
  central promptly costs one small request, and central decides who is emailed.
- **The alert can be derived.** "Something has been stuck for a day" is visible from the reports themselves. A status line
  computed from them can't get out of step, needs no acknowledgement and shows the same information.
- **The one thing it adds is a forced look.** An alert someone must acknowledge is a stronger prompt than a red status line
  that someone must open the page to see. If that matters, a small step in this proposal's direction is to also show the
  red state in the existing site banner, with no new storage.

### Other alternatives

- **Retry forever with no visible state.** A site could go weeks without reporting and nobody would know. The status line
  is what prevents that.
- **Make this per reporter.** It's a site decision, and the reporter's own choice is already the Urgent checkbox.

## Open questions

- **The numbers.** Start at 5 minutes, cap at 1 hour, and the command ticks every few minutes. Right for a site that
  comes back online mid-day?
- **Should the red state also use the site banner**, so someone sees it without opening `/setup/feedback`? (Uses
  `BannerSetting`, so the banner would have to be set and cleared by the job. Probably a later step.)
- **Should the central instance notice** that a remote it expects hasn't been heard from in a long time? It can't be told
  over a broken link. This is a separate, later idea.

## What it touches

- `FeedbackPushService` (one backoff key; `pushOne` and `pushUnsent` consult and update it), a new `feedback:push-unsent`
  command, and the Feedback page's status line (`/setup/feedback`, `manage-feedback`).
- `docker/scheduler.sh` and a systemd timer.
- No migrations, no new settings, no changes to who may see the page.
- Related: proposal 0002 (digest and Developer role, the receiving side); Offline Mode.
