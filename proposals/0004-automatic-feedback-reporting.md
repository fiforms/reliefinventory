# 0004 — Automatic feedback reporting to a central instance

- **Status:** Under discussion
- **Lane:** Shared (feedback aggregation, an admin setting). How the settings, failure count and alert are stored is
  Daniel's call.
- **Author:** Mark
- **Idea:** none in ROADMAP.md; raised directly by Mark on 2026-10-26. The sending-side companion to proposal 0002
  (digest on the receiving side).

## Problem

An instance linked to a central instance (Feedback page > Send reports to) sends a report **once, at the moment it's
saved**. That happens only when Offline Mode is off (`FeedbackPushService::pushOne`). If that one attempt fails, the
report sits unsent until someone opens `/setup/feedback` and presses **Send Unsent Reports**. Nothing retries it.
Nobody is told it failed, and there's no way to choose *how* reports are sent.

That's a poor fit for where this software runs. A field site's connection comes and goes, so the reports that matter
most, urgent ones, are the likeliest to be lost until someone remembers the button. Equally, an instance in a busy
class sends every report the instant it's filed, and nobody local can change that.

## Proposed direction

### One setting, chosen by whoever runs the instance

On the Feedback page, next to **Send reports to**, an **Automatic reporting** section that the person managing the
instance (`manage-feedback`, like the rest of that page) sets once:

- **Automatic reporting: On / Off.**
  - **Off:** reports are only sent when someone presses **Send Unsent Reports**.
  - **On:** reports are sent without anyone doing anything, by the rules below.
- **Urgent reports** (the reporter ticked Urgent / blocking) are **always sent immediately** while automatic reporting is
  on. If that fails, they're retried until they get through or the failure rule below turns automatic reporting off.
- **Everything else** (non-urgent bugs and feature requests), one of:
  - **Immediately:** as today.
  - **Daily, at a set time:** everything waiting is sent together, once a day at the hour chosen.
- **Offline Mode still wins.** While an instance is in Offline Mode nothing is sent automatically, urgent included,
  because Offline Mode is the admin's decision not to use the connection. Reports wait, and go on the next automatic
  attempt after Offline Mode is turned off. **Send Unsent Reports** still works in Offline Mode, as today.

### When sending keeps failing

- An **attempt** is one automatic try to send. It's the first send of a report, a retry of urgent reports, or the
  daily batch.
- **After 3 automatic attempts in a row fail, whatever the reason, automatic reporting turns itself Off.** Reports stay
  saved and queued. Nothing is lost, and **Send Unsent Reports** still works.
- At the same moment an **alert** is raised:
  - It's shown to anyone with `manage-feedback`: in the notification bell, and as a red notice at the top of the
    Feedback page.
  - It says what happened, when, and the last error in plain words (for example: "the other instance revoked this
    link", "this instance isn't allowed to send feedback there", "couldn't reach the other instance"). These messages
    already exist (`pushUnsent()['error']`).
- **The alert stays until an administrator or developer acknowledges it.** Acknowledging records who did it and when,
  and clears the notice for everyone.
- Turning automatic reporting back on is a separate, deliberate step. Re-enabling it resets the failure count.
- A successful attempt, automatic or **Send Unsent Reports**, resets the count to zero.

### Retrying

The app has no Laravel scheduler. Hourly jobs run from `docker/scheduler.sh` (Docker, R Station, Launcher) and systemd
timers on bare metal. Automatic reporting needs a periodic job for urgent retries and the daily batch, added in both
places like the volunteer-sync timer. Between runs, a new report being saved is also an attempt, so an instance in use
retries sooner.

## Rejected alternatives

- **Treat "can't connect" differently from "refused"** (keep retrying through an outage, and turn off only when the other
  instance refuses). Raised during discussion. Mark chose a single rule: three failures in a row, whatever the cause,
  turn it off and alert. It's simpler to explain and makes a person look at it every time. The trade-off is written
  down here: at a site with a patchy connection, an outage alone will turn automatic reporting off. Urgent reports then
  wait until someone acknowledges the alert and turns it back on, or presses **Send Unsent Reports**.
- **Retry forever with no alert.** A site could go weeks without reporting anything and nobody would know.
- **Make this per reporter.** It's a site decision. The reporter's choice is already the Urgent checkbox.

## Open questions

- **Spacing of attempts.** With an hourly job, three failed attempts on an urgent report take about two to three hours.
  Is that right, or should urgent retries run more often (say every 10 minutes), which needs a more frequent job than the
  current hourly one?
- **Who may acknowledge.** "Administrator or developer": holders of `manage-feedback`, or a narrower permission? If
  proposal 0002's Developer role is accepted, developers hold everything anyway.
- **Does acknowledging also offer "turn it back on"** in the same step, or stay separate as above?
- **Should the central instance be told** that a remote stopped reporting? It can't be told over a broken link, but it
  could notice a remote it hasn't heard from in a long time. That's probably a later idea.
- **Storage (Daniel).** The on/off and mode settings sit naturally beside the existing `FeedbackAggregationSetting`. The
  failure count, last error and the alert with its acknowledgement need a home. Could the alert reuse the existing
  `notifications` table and the bell, or does it need its own record so "acknowledged by whom" is kept?

## What it touches

- `FeedbackPushService` (it attempts on save today; this adds retries, the daily batch and the failure rule),
  `FeedbackReportController::store`, and `FeedbackAggregationSetting`.
- The Feedback page (`/setup/feedback`): the Automatic reporting section and the alert notice. The notification bell.
- `docker/scheduler.sh` and a systemd timer.
- Related: proposal 0002 (digest and Developer role, the receiving side); Offline Mode.
