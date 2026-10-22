@extends('reports.help.layout')

@section('title', 'Order Filling Guide')

@section('content')
    <p class="intro">Pick the items for requests that have been entered at
        Order Entry,
        and record what was actually pulled. This is where stock leaves the inventory.</p>


    <div class="section">
        <h2>How filling works</h2>
        <p>
            You need the <strong>manage-orders</strong> permission. Open
            Order Filling.
            There are two ways to work, and they end up in the same place:
        </p>
        <ul>
            <li>
                <strong>On paper</strong> &mdash; print pick sheets, walk the warehouse with them, then
                type in what was picked.
            </li>
            <li>
                <strong>On screen</strong> &mdash; open one order with <strong>Start Filling</strong> and
                enter each pick as you go.
            </li>
        </ul>
        <p>
            Every pick is saved as its own <strong>fill record</strong> the moment you add it.
            Records are only ever added, never overwritten: if a line is picked in two trips, both
            records are kept and the line's <strong>Filled</strong> number is their total. A fill
            record says how many were taken and, if they came off a warehouse pallet, which pallet
            &mdash; that is where the goods were, not who donated them.
        </p>
    </div>

    <div class="section">
        <h2>The two queues</h2>
        <ul>
            <li>
                <strong>Ready to Fill</strong> &mdash; submitted orders waiting to be worked (Order,
                Partner, Lines). <strong>Start Filling</strong> on a row moves just that order to
                Filling and opens it.
            </li>
            <li>
                <strong>Filling</strong> &mdash; orders already started, with progress such as
                <em>2 of 5 lines filled</em>. <strong>Continue</strong> reopens the order; the
                <strong>Print</strong> menu on that row reprints its pick sheet.
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>Paper path: Print Pick Sheets</h2>
        <p>
            <strong>Print Pick Sheets</strong> (top right) prints one sheet per order for
            <em>every</em> order in Ready to Fill, listing where to find each item along the
            warehouse's pick path. Choose <strong>Download PDF</strong> or a printer.
        </p>
        <p>
            Important: choosing any option here moves <strong>all</strong> the Ready to Fill orders
            into Filling at once, and from then on their picker quantities are fixed. The button is
            greyed out when nothing is Ready to Fill. After picking, click
            <strong>Continue</strong> on each order and enter what was pulled, as below.
        </p>
    </div>

    <div class="section">
        <h2>Optional: Review Allocation (when there isn't enough)</h2>
        <p>
            <strong>Review Allocation</strong> (top right; click again to
            <strong>Hide</strong> it) lists items where the total requested across all Ready to
            Fill orders is more than what is on hand. For each item you see a table with
            <strong>Order</strong>, <strong>Partner</strong>, <strong>Requested</strong>,
            <strong>Suggested</strong>, <strong>Approve</strong> and <strong>Need</strong>.
        </p>
        <ul>
            <li>
                <strong>Suggested</strong> is a plain proportional split of what we have. It is a
                starting point, not a rule.
            </li>
            <li>
                <strong>Need</strong> is the partner's own Critical / Moderate / Low note from Order
                Entry (a dash if none). Lines are listed most-urgent first.
            </li>
            <li>
                Type the quantity you want in <strong>Approve</strong> and click
                <strong>Save Approved Quantities</strong>. Pickers then see only the approved number
                as <strong>Qty to Pick</strong> on the pick sheet and the fill screen. Approve
                <strong>0</strong> and the line is left off entirely. Leave a box blank to give the
                picker the full request. A check mark shows a saved approval.
            </li>
            <li>
                You can't approve more than was requested, and approvals can only be changed while the
                order is still Ready to Fill. The original request is never changed, so reports can
                still compare requested, approved and filled.
            </li>
            <li>
                Nothing here is required. If you'd rather go first-come, first-served, ignore this
                panel and print or start filling.
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>The fill screen</h2>
        <p>
            The screen is titled <strong>Order #</strong> with the partner's name.
            <strong>&larr; Back</strong> returns to the queues. The table has columns
            <strong>Item</strong>, <strong>Qty to Pick</strong>, <strong>Filled</strong>,
            <strong>On Hand</strong> and <strong>Add Fill</strong>, listed in the order you would
            walk the warehouse.
        </p>
        <ol>
            <li>
                Look at the <strong>Pick:</strong> suggestions under a line (see the next section).
            </li>
            <li>
                If the item comes in more than one variety, search or scan the right one into
                <strong>Scan or search item...</strong> (or use <strong>Scan</strong> for the
                camera). If there is only one variety it is chosen for you.
            </li>
            <li>
                Type the <strong>Qty</strong> you picked. If it came off a specific warehouse pallet,
                type or scan that pallet's tag into <strong>From open pallet (optional)</strong>; the
                tag stays in the box for your next pick. Press <strong>Enter</strong> or click
                <strong>Add</strong>.
            </li>
            <li>
                The pick appears in a list under the line (quantity and item). To remove a mistake,
                click the trash icon, then <strong>Delete?</strong> (or <strong>Keep</strong>). The
                stock goes back, and a pallet that had been marked empty by that pick is reopened.
            </li>
        </ol>
        <p>
            You can enter <strong>0</strong> on purpose when you looked and there was none: that
            counts as "dealt with" and doesn't take any stock.
        </p>
    </div>

    <div class="section">
        <h2>"Pick:" suggestions and where stock is</h2>
        <p>
            Under each line, <strong>Pick:</strong> shows boxes saying where to take the item from,
            recalculated from live stock each time. They are advice only &mdash; nothing is reserved
            and you can pick differently.
        </p>
        <ul>
            <li>
                <strong>Sorting area &times;N</strong> &mdash; loose stock not yet put on a pallet. It is used
                first.
            </li>
            <li>
                <strong>Location, pallet tag &times;N</strong> &mdash; take N from that pallet (with an
                expiration date if one is known). Pallets that expire first, then older pallets, are
                suggested first. Whole sealed pallets are suggested before opening any new one, so
                full pallets can ship intact.
            </li>
            <li>
                <strong>open</strong> means you can pick from that pallet now.
                <strong>sealed &mdash; take the whole pallet</strong> means the whole pallet is going on
                this order, so it can go as it is. <strong>sealed &mdash; open it first</strong> means part
                of it is needed: click <strong>Open pallet</strong> first. (Use is greyed out until
                you do.)
            </li>
            <li>
                <strong>Use</strong> copies a suggestion into the entry row (the pallet tag, the
                quantity and, where relevant, the item variety). Check it and click
                <strong>Add</strong>.
            </li>
            <li>
                <em>N not found in stock</em> means the system thinks we're short by N.
                <em>fully picked</em> means nothing more is needed on that line.
            </li>
        </ul>
        <p>
            More on pallets, sealing and locations:
            Inventory Movement.
        </p>
    </div>

    <div class="section">
        <h2>When the numbers don't match</h2>
        <ul>
            <li>
                <strong>No pallet named:</strong> the pick comes from the sorting area first, then
                from the oldest <em>open</em> pallets, split into several fill records if needed.
            </li>
            <li>
                <strong>Sealed pallets hold the rest:</strong> you will get a message such as
                <em>Only 30 of 50 can come from the sorting area and open pallets. Open a sealed
                pallet to pick the other 20.</em> Nothing is saved. Open a pallet (use
                <strong>Open pallet</strong> on the suggestion) and add the pick again.
            </li>
            <li>
                <strong>Naming a sealed pallet:</strong> it only works if you are taking everything on
                it. Otherwise open it first. A tag that isn't a warehouse pallet is refused
                (<em>Unknown Warehouse pallet tag</em>), as is a pallet that is empty or otherwise
                can't be picked from.
            </li>
            <li>
                <strong>More than the system says we have:</strong> the pick is accepted, with a
                warning such as <em>That's 5 more than the database says is in stock &mdash; check the
                count.</em> It means the records and the shelf disagree: count the shelf, and tell a
                supervisor.
            </li>
            <li>
                A pallet is marked empty automatically when its recorded total reaches zero.
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>Finishing the order</h2>
        <ol>
            <li>
                Every line that has something to pick needs at least one fill record. A line with
                nothing approved to pick (approved at 0) is not on the screen and needs none. Until
                all are done, <strong>Complete Filling</strong> is greyed out and the message says
                <em>Every line needs at least one fill record (even 0)&hellip;</em>.
            </li>
            <li>
                Optionally enter <strong>Pallets packed</strong> (how many pallets the order ended
                up on) &mdash; it is recorded on the order.
            </li>
            <li>
                Click <strong>Complete Filling</strong>. The order becomes <strong>Filled</strong>
                and moves on to
                Shipping.
            </li>
        </ol>
        <p>
            <strong>If nothing at all was picked</strong> (every fill is 0), the screen warns
            <em>Nothing has been picked &mdash; completing will close this request as Unfulfilled instead
            of sending it to Shipping.</em> The order then ends as
            <strong>Closed - Unfulfilled</strong> and never reaches Shipping.
        </p>
        <p>
            Once an order is Filled, its fill records can no longer be changed here.
        </p>
    </div>
@endsection
