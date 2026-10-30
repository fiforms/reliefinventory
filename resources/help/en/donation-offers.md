<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

Keep track of donations that are offered by phone (or email, or in person) before anything ships, decide whether to
accept them, and tie each accepted one to the load when it arrives at [Receiving](/help/receiving).

## What offers are for

Not every donation is phoned in first — a walk-in or a surprise truck is logged straight at Receiving and never needs an
offer. But when someone calls ahead, an offer gives everyone one place to see what's coming, who agreed to take it, and
when it's expected. Each offer keeps a full history of every call and decision.

Open it from the **Donation Offers** button on [Receiving](/receiving). Anyone who can use Receiving can open the page,
log a call and edit an open offer. **Deciding** an offer (Approve, Refuse, Divert, Cancel Offer, Match to Arrival) needs
the separate **Donation Offers** permission. Without it you can still log and update offers, and the decision buttons
are replaced by a note saying the permission is required.

## The life of an offer

- **Offered** — someone called; nobody has decided yet.
- **Pending Arrival** — accepted (Approve). We're expecting it by the ETA window.
- **Received** — it arrived and was matched to a Receiving record. Finished.
- **Refused** or **Diverted** — we said no, or sent the donor to another organization. Finished.
- **Cancelled** — an accepted offer that fell through. Finished.

Only Offered and Pending Arrival offers can be edited. Once an offer is Refused, Diverted, Cancelled or Received it is
kept as history and locked. A pending offer whose ETA window (or single ETA day) has fully passed shows an **overdue**
tag in the list.

## Logging a new offer

1. Click **Log a Donation Offer**.
2. Pick the **Donor / Source**. If they aren't in the system, use the create option (a first name, last name, or
   organization is enough).
3. If the donor is an organization, a **Contact Person** box appears. Pick or add the person who made the call.
4. Describe **What's being offered**, and, if the donor knows it, fill in the **Rough ETA Window (if known)** dates. You
   can also record **How did the offer come in?** and any **Notes from the conversation**; they go in the offer's first
   history entry.
5. Save. The offer starts as **Offered** and its history shows **Logged as Offered**.

## Working the list

The list shows each offer's donor, status, ETA (date range) and description. Offers with the soonest ETA are at the top;
offers with no ETA are at the bottom. Tick **Pending (ETA-sorted) only** to see just the accepted offers we're waiting
on — a handy "what's coming?" view. The list shows every open offer plus the 150 most recent finished ones. **← Back to
Receiving** returns to the intake page.

## Deciding an offer

Open an offer and look at **Donor History** first — it lists that donor's earlier donations, requests and offers, so the
decision isn't made blind. Then, under **Decision** (these buttons need the Donation Offers permission), choose one.
Each opens a short form; fill it in and press **Confirm**. You can also pick a **Contact Method** (Phone, Email, In
Person, Other) and add **Notes**, which are recorded in the history.

### On an Offered offer

- **Approve** — accepts it. Enter the **ETA Window** (the start date is required, the end is optional) and any **Transit
  Notes**. The offer becomes Pending Arrival.
- **Refuse** — enter the **Reason (required)**.
- **Divert** — enter **Diverted to (required)**, the organization the donor was sent to.

### On a Pending Arrival offer

- **Cancel Offer** — the donation isn't coming after all. Enter the **Reason (required)**.
- **Match to Arrival** — see the next section.

### On either

- **Log a Call / Update ETA** — the donor called back to move the date or add detail, and nothing is decided. Change the
  ETA Window or the description if needed and write a note (required). The status stays the same and the call is added
  to the history.

## When the donation arrives: matching

Matching links the accepted offer to the actual Receiving record and marks the offer **Received**. There are three ways,
all with the same result:

1. **At the dock (easiest).** On [Receiving](/receiving), click **Record an Intake**. At the top, under **Does this
   match a phoned-in offer?**, click the card for this donor. The donor, contact person and description are filled in
   for you, and the offer is marked Received when you save the intake.
2. **From the offer.** Open the Pending Arrival offer and click the link **start a new Receiving intake for this
   offer**. Receiving opens a new intake with the offer already selected.
3. **After the fact.** If the load was already entered at Receiving without picking the offer, open the offer, click
   **Match to Arrival**, choose the intake under **Match to arrived donation** (search the list of intakes that aren't
   linked to an offer yet — the 50 most recent are offered), and press **Confirm**.

A Receiving record can be linked to only one offer, and only a Pending Arrival offer can be matched.

## History

At the bottom of every offer, **History** lists every step: who did it, when, how the donor was contacted, and the
notes. Entries read like **Offered → Pending Arrival** for a decision, **Update — Pending Arrival** for a call that
changed nothing but the details, and **Logged as Offered** for the first entry. Nothing in the history can be edited or
removed.
