# 0003 — Remote support: a read-only mirror of a user's app screen

- **Status:** Under discussion
- **Lane:** Shared (trust, privacy, UX). A small piece of storage (support sessions and their audit) is Daniel's call.
- **Author:** Mark
- **Idea:** feedback report #95 on demo (2026-10-25); no ROADMAP.md entry yet

## Problem

When someone at a warehouse is stuck, the only ways to help today are a phone call ("what do you see?"), a feedback
report with an optional screenshot, or a general screen-share tool (Zoom, Teams). Each one falls short:

- A call can't show what's on the screen. Most stuck moments are about *what the screen shows*: a missing button, a
  filtered list, an error under a field.
- A feedback screenshot is one frozen picture after the fact.
- A general screen share has to be installed and set up, shares the whole computer (email, other tabs, other
  people's data), and on a shared warehouse terminal it often isn't available at all. On an R Station or a phone it's
  awkward or impossible.

Mark's idea: reuse the trust and pairing pieces we already have to let a supporter **see exactly what the user sees in
Relief Inventory, live, read-only.** It's only the app, not the whole computer.

## Requirements

1. **The user starts it, every time.** Nobody can watch a session the user didn't ask to share. A supporter can't start
   a session from their side, even with the highest permission.
2. **The user knows it's happening.** A banner on every page says "<name> is viewing your screen", with a **Stop**
   button that ends it at once.
3. **Read-only by construction.** The supporter sees the screen and the pointer. They can't click, type or navigate for
   the user. It isn't "read-only unless", it's read-only always.
4. **Only the app.** No other tabs, windows, notifications or desktop. No operating-system screen-share prompt.
5. **Sensitive fields are hidden.** Password and PIN fields are never shown. By default the values typed into any field
   are masked. The user can choose "show what I type" for this session when the problem is in the typing.
6. **It ends on its own.** It ends on a time limit (say 30 minutes, extendable by the user), at logout, or when either
   side closes it.
7. **Recorded, not retained.** Every session is audited: who asked, who viewed, when, and how long. The screen content is
   not kept after the session ends.
8. **Works without Reverb.** Realtime is optional on every instance, so this must work by polling too, more slowly.

## Proposed direction

### What gets mirrored

Mirror the **app's page structure (the DOM)**, not pixels. The user's browser records the page and streams changes; the
supporter's browser rebuilds the page in a sandboxed frame. **rrweb** is the established open-source library for exactly
this: a snapshot plus a stream of changes, the pointer and scrolling, with input masking built in. It's small next to
video, works on phones and R Stations, and the viewer can't send anything back, because the replay frame has no channel
to the user's page.

The fallback, if rrweb ever disappoints, is periodic snapshots with `html2canvas`, which the feedback form already uses.
That's choppier but simpler.

### How a session starts (reusing what exists)

1. The user opens **Get Help → Share my screen with support** from the profile menu.
2. They get a short **support code**: single use, expires in about 10 minutes. It's the same shape as federation's
   pairing codes (`trust_pairing_codes`: stored as a hash, single use, `lockForUpdate` redemption).
3. They read the code to the supporter over the phone. The supporter, holding **provide remote support**, enters it.
   Alternatively, they pick the user from **Who's Logged In** (`/setup/active-sessions`), which sends the user a request
   instead.
4. **The user approves the named supporter** ("Mark Kent wants to view your screen — Allow / Deny"). Nothing streams
   before that.
5. While it runs, both sides see the session in progress. Either side can stop it.

The permission is the developer-only **provide remote support** from proposal 0002. An instance's own Administrators
could be allowed to support their own staff later; that's a policy choice, not a technical one (see open questions).

### Moving the data

- **With Reverb:** a private channel per session, authorized for exactly the two people in it through the session login,
  like the existing `print-user.{id}` channels in `routes/channels.php`.
- **Reverb's message limit defaults to 10 KB** (`config/reverb.php`), and a first page snapshot is far bigger. So the user's
  browser **uploads the changes to the server** in small batches, and Reverb only says "new data, fetch now". This is
  the same poll-and-wake pattern the print queue already uses, which means it also works by plain polling when Reverb
  is off.
- The server holds batches only for the live session, and deletes them when it ends.

### Across instances

The common case is a developer on central (demo) supporting a user on another instance (cs26). The supporter would need
to see the session there. Two directions, to decide together:

- **(a) Local account only (smallest).** The supporter must have a Developer account on that instance. Nothing new
  crosses instances.
- **(b) Over a trust.** A new trust permission, for example `support.view`, lets a paired instance's developers view
  sessions on this one, still started and approved by the user. The viewer would fetch batches with a signed request
  through `FederationClient`. This builds on the federation system rather than inventing a new channel.

Suggest building (a) first and (b) only once (a) has been used for real.

## Rejected alternatives

- **Real screen sharing (WebRTC `getDisplayMedia`).** It shares pixels of the whole tab, window or screen, needs an
  operating-system prompt the user may not understand, is heavy on a warehouse's bandwidth, and leaks whatever else is
  on the screen. It also isn't available on every device we run on.
- **Replaying the page's data on the supporter's side** (sending the Inertia page name and props). Many pages load
  their data separately from `/json/*` and keep their own local state, so the supporter would see a different screen
  from the user's. Worse, the supporter's browser would load data with the *supporter's* permissions.
- **"Log in as the user" (impersonation).** It's the opposite of read-only, makes every audit field ambiguous, and is
  exactly the capability we'd least want misused.
- **Using the feedback form's screenshot on a timer.** This is the fallback above, not the main approach: it's laggy,
  and every frame is a large image.

## Open questions

- **Who may provide support?** Developers only, or also an instance's own Administrators for their own staff?
- **Kiosk and R Station screens.** A device session (`device_session`) has no logged-in person to approve the request.
  Leave them out, or let the device show its own on-screen approval?
- **Cross-instance (a) or (b) first**, and if (b), whether `support.view` belongs in `TrustPermission` at a high risk
  tier.
- **Storage (Daniel).** A support session needs a record (requester, supporter, start and end time, how it ended, the
  code's hash) and a short-lived place for the batches. Is that a table plus files, a table plus cache, or something else?
- **Dependency.** rrweb would be a new frontend dependency, a few tens of KB gzipped for the recorder (to measure). It should load only
  when a session starts, the same way `html2canvas` loads only when a screenshot is taken.
- **Audio.** Out of scope: the phone call stays the voice channel.

## What it touches

- Profile menu (Share my screen), a banner in `AuthenticatedLayout.vue`, a supporter viewer page, and
  `/setup/active-sessions` (Request to view).
- `routes/channels.php` (a session channel), a small upload/fetch endpoint pair, and the audit and activity logs.
- Proposal 0002 (the **provide remote support** permission and the Developer role). Federation (`TrustPermission`)
  only if cross-instance option (b) is chosen.
