# Proposals — agreeing on direction before we build

Relief Inventory has two developers, Mark and Daniel, who mostly build on their own, each with Claude Code. This folder
is how we agree on direction *before* either of us builds something big, so neither of us redoes or undoes the other's
work. We'll always change and edit things afterwards. The point is to agree on where we're going first.

## Three kinds of change

| Kind | What it is | What to do |
|------|------------|------------|
| **Bug** | Something clearly not working: an error, a wrong result, or UI that is sloppy or misplaced in a way that blocks or covers other things on the page. | **Fix it.** No discussion needed. Get it working; any later change to how it looks or where it sits is a suggestion. |
| **Suggestion** | A change to something *already built* that one of us thinks should be better or different. This includes preference-level UI: a button at the top or bottom, wording, the order of fields. | **Discuss before building.** Open a GitHub Issue labeled `suggestion` that says what, where and **why**. Build it once the other person agrees. |
| **Idea** | Something new that doesn't exist yet. | Add it to [`ROADMAP.md`](../ROADMAP.md). Before anyone builds it, it becomes a **proposal** here. |

There is no exemption for "small" UI changes. What's small to one of us can be a long decision for the other.

## Lanes

We each lead where our experience is:

- **Database schema and data model: Daniel.** Migrations, tables, columns, indexes, how data is stored and scoped.
- **Warehouse operational flows: Mark.** What staff actually do on the floor and in what order: receiving, sorting,
  inventory and pallets, picking, shipping, the order and donation lifecycles. A flow that only works in theory and
  not in a real warehouse is out.
- **UX: shared**, once the direction underneath it is agreed.
- **Everything else** (Docker, R Station, printing, backups, deploy, infrastructure): no lane. A big change still needs
  an agreed proposal, and whoever proposes it can build it.

Both of us have influence everywhere. The lane owner writes or approves the design in their lane and has the final say
after hearing the other. The other person's concerns are written into the proposal, not dropped. **Once a proposal is
accepted, either of us can build any part of it.**

Keep it lean. The system works and should stay lightweight. Build the smallest thing that does the job, and don't add a
table, dependency or service without a stated reason.

## What needs a proposal

- Any database schema or migration change.
- Any change to an operational warehouse flow, including statuses and lifecycles.
- Any change to the permission model.
- Big infrastructure changes and changes to shared components (RIForm, PrintMenu, the backup format, the print queue
  protocol, the device protocol).

## Lifecycle

1. **Idea:** an entry in `ROADMAP.md`. Anyone adds or edits one freely. Rough is fine.
2. **Proposal:** someone wants to build it, so it becomes `proposals/NNNN-short-name.md`, opened as a PR that contains
   only that file and links its ROADMAP entry. We discuss it in the PR's review comments and revise it there.
3. **Accepted:** the PR is merged once **both** of us approve.
4. **Built:** the implementing PR links the proposal. When it ships, set the proposal's status to Built, remove or mark
   its ROADMAP entry, and describe the shipped behaviour in `CLAUDE.md`.

A **Rejected** proposal stays here with its reason, so the same idea isn't argued again from scratch.

## Template

```markdown
# NNNN — Title

- **Status:** Draft | Under discussion | Accepted | Built | Rejected
- **Lane:** Database (Daniel) | Warehouse flow (Mark) | Shared | None
- **Author:** …
- **Idea:** ROADMAP.md §N (if any)

## Problem
What isn't working, or what we need, and for whom.

## Proposed direction
What we'd do. Enough detail to agree on, not an implementation plan.

## Rejected alternatives
What we considered and why not.

## Open questions
What the other lane owner, or both of us, still need to decide.

## What it touches
Tables, screens, flows, other proposals.
```

## Index

| # | Title | Lane | Status |
|---|-------|------|--------|
| [0001](0001-training-alongside-live.md) | Training alongside live work | Warehouse flow, with an open database question | Under discussion |
