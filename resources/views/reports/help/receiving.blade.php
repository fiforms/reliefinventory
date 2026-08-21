<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receiving Guide</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 0;
            padding: 36px 42px;
            font-size: 13px;
            line-height: 1.5;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 4px 0;
        }
        .subtitle {
            color: #666;
            font-size: 12px;
            margin-bottom: 18px;
        }
        .section {
            margin-top: 22px;
        }
        .section h2 {
            font-size: 14px;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 4px;
            margin-bottom: 10px;
            color: #1e3a8a;
        }
        ol, ul {
            margin: 0;
            padding-left: 20px;
        }
        li {
            margin-bottom: 8px;
        }
        .intro {
            background: #f3f4f6;
            border-radius: 6px;
            padding: 10px 14px;
            font-style: italic;
        }
        .footer {
            font-size: 10px;
            color: #888;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <h1>Receiving Guide</h1>
    <div class="subtitle">{{ config('app.name') }} &mdash; printed {{ now()->format('F j, Y') }}</div>

    <p class="intro">
        In order to get the most out of these instructions, we suggest you print them so you can
        follow along step by step without switching between screens. You can also use a separate
        device, or if your device supports split-screen, pull up both pages side by side.
    </p>

    <div class="section">
        <h2>Welcoming the driver &amp; safety</h2>
        <ul>
            <li>Introduce yourself and welcome the driver.</li>
            <li>If it's their first time at this warehouse, let them know where the restrooms are.</li>
            <li>Let them know you'll need to get some information from them for our records.</li>
            <li>No forklifts are allowed on or in box trucks.</li>
            <li>
                Whenever warehouse personnel need to enter the truck or trailer to unload it, the driver
                must be in the warehouse &mdash; not in the truck &mdash; while that's happening. Wheel
                chocks should be in place, and, where the warehouse is equipped for it, the trailer lock
                engaged before anyone enters the trailer.
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>What Receiving is for</h2>
        <p>
            A Receiving record is the dock-side log entry for one arrival: who it came from (or
            "unknown" for now), roughly how much of it there is, and &mdash; for donations &mdash;
            the labels that get tagged and sent on to Donation Sorting. It is <em>not</em> the
            detailed item-by-item count.
        </p>
    </div>

    <div class="section">
        <h2>Step by step</h2>
        <ol>
            <li>
                Open Receiving and click <strong>Record an Intake</strong> to start a record for the
                arrival in front of you.
            </li>
            <li>
                Pick a <strong>Category</strong> &mdash; Donation, Equipment, Supplies, or Other.
                Equipment and supply deliveries are for items the warehouse itself will use, not
                donations that will be distributed. Only Donations get labels tracked through sorting;
                the others are logged for the record but don't flow into the sorting queue.
            </li>
            <li>
                Search for the <strong>Donor/Source</strong> by name or organization. If they're not
                in the system yet, use the inline "create new donor" option &mdash; a first name, last
                name, or organization is enough, they don't all have to be filled in.
            </li>
            <li>
                Fill in <strong>Where did this donation come from?</strong> with whatever location
                information the driver can give you, every time &mdash; regardless of whether you know
                exactly who the donor is.
            </li>
            <li>
                If you genuinely don't know who dropped this off, check <strong>"Donor
                Unidentified"</strong> instead of guessing. This just flags the record for follow-up
                &mdash; it stays visible in the Receiving list (even after the donation is marked
                Complete) until someone follows up and fixes the source.
            </li>
            <li>
                Answer <strong>Did this shipment arrive on pallets?</strong> and fill in the
                <strong>Number of Pallets</strong> &mdash; fill in the count either way, even if it
                didn't arrive palletized (it will likely still be palletized here at the warehouse for
                the move to sorting).
            </li>
            <li>
                Pick <strong>How did this arrive?</strong> (Semi, Box Truck, Personal Vehicle, or
                Other), and fill in the <strong>Driver Name</strong> and <strong>Driver Phone</strong>
                you gathered when you welcomed them.
            </li>
            <li>
                Fill in whatever you can of the <strong>Manifest</strong> (what the paperwork says came
                in) and <strong>Comments</strong>. None of these block sorting &mdash; they're context
                for whoever works this donation next.
            </li>
            <li><strong>Save.</strong> For a Donation, the record now lets you add labels.</li>
            <li>
                For each physical unit &mdash; pallet, gaylord, box, or bag &mdash; enter the quantity,
                pick the container type, and add a short description (and an item tag if you already
                know what's on it), then click <strong>Add Label(s)</strong>. Each one gets a barcode
                tag, whether or not it's on a pallet.
            </li>
            <li>
                Click <strong>Print All Labels</strong> to print one combined PDF of every label for
                this donation. Attach each printed label to its pallet, gaylord, box, or bag before it
                leaves the dock.
            </li>
            <li>The received donation is now ready to go to the staging area for sorting.</li>
        </ol>
    </div>

    <div class="footer">
        Questions or something not working the way this guide describes? Use Report an Issue from the
        profile menu in the app.
    </div>
</body>
</html>
