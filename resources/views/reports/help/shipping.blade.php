@extends('reports.help.layout')

@section('title', 'Shipping Guide')

@section('content')
    <p class="intro">
        Hand finished orders to a driver, record when the truck leaves, and review the signed Bill of Lading (BOL) that comes back. Orders get here once Order Filling marks them Filled.
    </p>

    <div class="section">
        <h2>How an order moves</h2>
        <p>
            Open Shipping. It
            has one table per stage, in this order, each with a count in its heading. You
            need the Manage Orders permission to use the page.
        </p>
        <ol>
            <li><strong>Filled &mdash; Ready to Assign a Driver</strong> &mdash; picking is done and no driver is assigned yet.</li>
            <li><strong>Ready to Ship</strong> &mdash; a driver is assigned, but the truck hasn't left.</li>
            <li><strong>Shipped</strong> &mdash; staff confirmed the load left the dock.</li>
            <li><strong>Delivered &mdash; Pending Review</strong> &mdash; the signed BOL has been uploaded and a manager needs to check it.</li>
            <li><strong>Delivered &mdash; Waiting on BOL</strong> &mdash; the uploaded BOL was rejected; a usable one is still needed.</li>
            <li><strong>Recently Completed</strong> &mdash; the signed BOL was approved (the 25 most recent).</li>
        </ol>
        <p>
            Nothing moves on its own: each step below is something a person does. The page
            has no other shipping steps, such as tracking numbers or delivery dates.
        </p>
    </div>

    <div class="section">
        <h2>Assigning a driver</h2>
        <ol>
            <li>
                In <strong>Filled &mdash; Ready to Assign a Driver</strong>, each row shows the order
                number, partner, number of lines and total quantity.
            </li>
            <li>
                Pick a name from <strong>Select a driver...</strong>, then click
                <strong>Assign</strong>. The order moves to Ready to Ship.
            </li>
        </ol>
        <p>
            A driver has to be in the <strong>Drivers</strong> list at the bottom of the page
            before you can pick them (see below).
        </p>
    </div>

    <div class="section">
        <h2>Printing the BOL</h2>
        <p>
            In the <strong>Ready to Ship</strong> and <strong>Shipped</strong> tables, the
            <strong>Generated BOL</strong> column has a <strong>View BOL</strong> menu. Use it to
            download the PDF or send it to a printer set up for your site. Print it for the driver
            to sign. The BOL number is assigned the first time it's generated and stays the same if
            you print it again.
        </p>
        <p>
            Next to it is a <strong>Shipping Label</strong> menu. It makes a 4 x 6.5 in label to stick
            on the shipment, showing the order number (with a barcode), who and where it is going,
            the contact, fulfillment method, number of pallets, date packed, requested date and the
            special instructions (cut short if they are long). Only printers loaded with large
            labels are offered; the PDF download is always available. The pallet count comes from
            <strong>Pallets packed</strong> in Order Filling, and the label is blank there if it
            wasn't entered. The date packed is when the last item was picked.
        </p>
    </div>

    <div class="section">
        <h2>Marking an order Shipped</h2>
        <p>
            When the truck actually leaves the dock, click <strong>Mark Shipped</strong> on that
            order in the <strong>Ready to Ship</strong> table. It is a manual step &mdash; nothing
            marks it for you.
        </p>
        <p>
            Picked the wrong driver? The Ready to Ship table has no way to change the driver
            once one is assigned, so check the name before you click Assign.
        </p>
    </div>

    <div class="section">
        <h2>Drivers and the Driver Portal</h2>
        <p>
            A driver doesn't need an account. The <strong>Drivers</strong> table at the bottom
            lists each driver's name, phone, carrier and whether a Driver Portal PIN is set.
        </p>
        <ul>
            <li>
                <strong>+ New Driver</strong> &mdash; enter a name (required), phone, and carrier (if a
                trucking company), then <strong>Save Driver</strong>.
            </li>
            <li>
                <strong>Set PIN</strong> / <strong>Reset PIN</strong> &mdash; enter a 5-digit PIN twice and
                click <strong>Save PIN</strong>. Easy-to-guess PINs are refused.
            </li>
        </ul>
        <p>
            Give the driver their phone number and PIN. They sign in at
            /driver-portal
            using <strong>Phone Number</strong> and <strong>PIN</strong>, and see only the loads
            assigned to them under <strong>Current Loads</strong> (Ready to Ship, Shipped, or
            waiting on a re-uploaded BOL). Each load shows delivery details (address, contact,
            window) under <strong>Delivery Details</strong> when the order has them.
        </p>
    </div>

    <div class="section">
        <h2>The signed BOL</h2>
        <p>
            After delivery, the driver taps <strong>Upload Signed BOL</strong> on the load and
            chooses a photo or PDF (JPG, PNG or PDF, up to 10 MB). The upload moves the order
            straight to <strong>Delivered</strong> &mdash; it doesn't have to be marked Shipped
            first. If a staff member opens the portal while logged in, they get a read-only list of
            every load currently out, with no upload button.
        </p>
        <p>
            The order then appears under <strong>Delivered &mdash; Pending Review</strong> on the Shipping
            page. A manager clicks <strong>Review</strong>, which opens the uploaded file:
        </p>
        <ul>
            <li>
                <strong>Approve</strong> &mdash; accepts it and moves the order to Completed. For a photo you
                can first drag a rectangle over the part to keep, and the button becomes
                <strong>Approve Cropped</strong> (a PDF can't be cropped, so open it in a new tab).
            </li>
            <li>
                <strong>Reject</strong>, then <strong>Confirm Reject</strong> &mdash; optionally say what's
                wrong. The bad file is discarded and the order goes to
                <strong>Delivered &mdash; Waiting on BOL</strong>. The driver sees the load again with your
                note and uploads a new one, which sends it back for review.
            </li>
        </ul>
        <p>
            Approved orders list a <strong>View Signed BOL</strong> link under
            <strong>Recently Completed</strong>.
        </p>
    </div>
@endsection
