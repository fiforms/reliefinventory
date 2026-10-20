@extends('reports.help.layout')

@section('title', 'Donation Sorting Guide')

@section('content')
    <p class="intro">
        Turn the pallets logged at Receiving into item-by-item ledger entries &mdash; this is where a
        donation actually becomes counted inventory.
    </p>

    <div class="section">
        <h2>What sorting is for</h2>
        <p>
            Sorting is scan-driven and saves as you go &mdash; every line you enter is sent to the
            server immediately, so a dropped connection or a crash never loses more than the one line
            you were on. Work one pallet at a time: scan its tag once, then every item you enter counts
            against that pallet until you scan (or clear) a different one. That records what each
            donation and pallet actually contained &mdash; once sorted, stock is pooled by item type.
        </p>
    </div>

    <div class="section">
        <h2>Starting or resuming a session</h2>
        <p>Open Donation Sorting to see three lists:</p>
        <ul>
            <li>
                <strong>Ready to Sort</strong> &mdash; donations logged at Receiving, waiting to be
                picked up. Tap one to start sorting it.
            </li>
            <li>
                <strong>In Progress</strong> &mdash; sessions someone already started. Tap to resume
                exactly where they left off.
            </li>
            <li><strong>Recently Completed</strong> &mdash; finished sessions, open read-only.</li>
        </ul>
        <p>
            <strong>Start Sorting (untagged)</strong>, at the top of the page, opens a brand-new
            session with no Receiving record behind it &mdash; use it only for goods that showed up
            without going through Receiving first. Its donor field is fully editable, unlike a session
            that came from Receiving.
        </p>
    </div>

    <div class="section">
        <h2>Working a pallet</h2>
        <ol>
            <li>
                Type or scan the pallet's tag (e.g. R00000042) into <strong>Pallet Tag</strong>, or tap
                <strong>Camera Scan</strong> to use the device camera, then <strong>Set Pallet</strong>.
                Everything you enter next is attributed to this pallet &mdash; lines entered with no
                pallet set still save, but won't be recorded against a source.
            </li>
            <li>
                For each item on the pallet: search or scan its UPC into the item field, enter a
                <strong>Qty</strong>, pick a <strong>disposition</strong> &mdash; <strong>New</strong>
                (unused, still in its original packaging &mdash; counts into inventory),
                <strong>Usable</strong> (used or opened, in good condition &mdash; counts into inventory),
                <strong>Outdated</strong> (expired on arrival), <strong>Trash</strong>
                (damaged/unusable), or <strong>Divert</strong> (usable, but passed to another
                organization) &mdash; then click <strong>Add</strong>. The disposition button you last
                used stays selected, since runs of the same kind of goods are common.
            </li>
            <li>
                Don't recognize an item? Type its name into the item search and choose
                <strong>Add "&hellip;" as a new item</strong> to open the quick-add form. A brand new
                item type needs a description, a unit (e.g. Each, Pound), and a category &mdash; all
                creatable inline without leaving the form.
            </li>
            <li>
                Made a mistake on a line? Click the trash icon next to it, then confirm &mdash; it's a
                two-tap delete, no popup.
            </li>
            <li>
                When the pallet is empty, click <strong>Pallet Empty</strong>, then say whether it
                <strong>looks OK</strong> or should be <strong>set aside &mdash; damaged</strong>.
                That's a one-tap note for a supervisor to follow up on later; it doesn't block anything
                here. Emptying the donation's last pallet automatically completes the donation.
            </li>
        </ol>
    </div>

    <div class="section">
        <h2>Session header &amp; wrapping up</h2>
        <ul>
            <li>
                For a session that came from Receiving, the donor shows read-only (it was already
                recorded at the dock) with an <strong>Edit</strong> link if it needs correcting. For an
                untagged session, the donor field is editable directly.
            </li>
            <li>
                <strong>Donor unidentified &mdash; flag for follow-up</strong> and
                <strong>Comments</strong> both save automatically as you change them &mdash; nothing to
                submit.
            </li>
            <li>
                Watch the save indicator in the top bar: <em>Saving&hellip;</em>, <em>All changes
                saved</em>, or a failure count with a <strong>Retry All</strong> button. Failed lines
                also retry automatically once your connection comes back.
            </li>
            <li>
                Click <strong>Complete Session</strong> when every pallet is done. If any lines haven't
                reached the server yet, it asks you to confirm before completing anyway.
            </li>
            <li>
                A completed session reopens read-only. If you need to fix something, use
                <strong>Reopen Session</strong> to go back to editing it.
            </li>
        </ul>
    </div>
@endsection
