{{-- Training Mode setup packet (TrainingAdminController::setupPacketBuilder): what the last Prepare Training loaded,
     so trainers can stage the props on the floor to match. $summary = training_settings.prepared_summary. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Training Setup Packet</title>
    <style>
        @page { size: letter; margin: 0.5in 0.55in; }
        body { font-family: Arial, sans-serif; color: #222; margin: 0; font-size: 11px; line-height: 1.4; }
        h1 { font-size: 22px; margin: 0; }
        .subtitle { color: #666; margin: 2px 0 12px 0; }
        h2 { font-size: 14px; color: #1e3a8a; border-bottom: 2px solid #1e3a8a; padding-bottom: 2px; margin: 14px 0 6px 0; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 6px; }
        th, td { border: 1px solid #bbb; padding: 3px 6px; text-align: left; vertical-align: top; }
        th { background: #eef2ff; }
        .note { color: #555; }
        .section { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>Training Setup Packet</h1>
    <div class="subtitle">{{ config('app.name') }} — prepared {{ \Carbon\Carbon::parse($summary['prepared_at'])->format('F j, Y g:i A') }}</div>

    @if (! empty($summary['missing_items']))
        <p class="note"><strong>Skipped</strong> (not in this instance's Master Item List): {{ implode(', ', $summary['missing_items']) }}</p>
    @endif

    <div class="section">
        <h2>Locations</h2>
        <p>Post a location sign at each spot: {{ implode(', ', $summary['locations']) }}. TR-DOCK is the receiving dock, TR-SORT the sorting area, TR-A-01 to TR-A-08 the racks.</p>
    </div>

    <div class="section">
        <h2>Station 1 — Receiving: offers waiting for arrival</h2>
        <p class="note">Stage props at the dock for each of these; the receiving team matches the delivery to the offer.</p>
        <table>
            <thead><tr><th>Offer #</th><th>Donor</th><th>What's coming</th></tr></thead>
            <tbody>
                @foreach ($summary['offers'] as $o)
                    <tr><td>{{ $o['id'] }}</td><td>{{ $o['donor'] }}</td><td>{{ $o['description'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Station 2 — Sorting: pallets waiting to be sorted</h2>
        <p class="note">Print these pallet labels (Setup &gt; Training Mode &gt; Print pallet labels) and put each on a pallet or box of mixed props at TR-DOCK.</p>
        <table>
            <thead><tr><th>Pallet</th><th>Donation #</th><th>Donor</th></tr></thead>
            <tbody>
                @foreach ($summary['pallets_to_sort'] as $p)
                    <tr><td>{{ $p['tag'] }}</td><td>{{ $p['donation_id'] }}</td><td>{{ $p['donor'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Stations 3 &amp; 4 — Stock already on the shelf</h2>
        <p class="note">Sealed warehouse pallets: stage each at its rack with these items on it.</p>
        <table>
            <thead><tr><th>Pallet</th><th>Location</th><th>Items</th></tr></thead>
            <tbody>
                @foreach ($summary['warehouse_pallets'] as $p)
                    <tr>
                        <td>{{ $p['tag'] }}</td><td>{{ $p['location'] }}</td>
                        <td>@foreach ($p['items'] as $i){{ $i['qty'] }} × {{ $i['number'] }} {{ $i['name'] }}@if (! $loop->last)<br>@endif @endforeach</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="note">In the sorting area (TR-SORT), not on any pallet yet — what Station 3 builds new pallets from:</p>
        <table>
            <thead><tr><th>Item</th><th>Qty</th></tr></thead>
            <tbody>
                @foreach ($summary['sorting_area'] as $i)
                    <tr><td>{{ $i['number'] }} {{ $i['name'] }}</td><td>{{ $i['qty'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @foreach ([['orders_to_fill', 'Station 4 — Picking: Ready to Fill orders', 'Picked from the shelf stock above.'],
               ['orders_to_ship', 'Station 5 — Shipping: Filled orders', 'Already picked: stage each as a load at the outbound door.'],
               ['orders_to_deliver', 'Station 6 — Delivery: Shipped loads, one per team driver', 'Each team uploads a signed BOL for its own load in Round 2; print a BOL for each (Shipping page) for them to "sign".'],
               ['orders_to_review', 'Station 6 — BOL review: Delivered orders waiting for review', 'A placeholder signed BOL is attached to each.']] as [$key, $title, $note])
        <div class="section">
            <h2>{{ $title }}</h2>
            <p class="note">{{ $note }}</p>
            <table>
                <thead><tr><th>Order #</th>@if ($key === 'orders_to_deliver')<th>Team</th>@endif<th>Partner</th><th>Lines</th></tr></thead>
                <tbody>
                    @foreach ($summary[$key] as $o)
                        <tr>
                            <td>{{ $o['id'] }}</td>
                            @if ($key === 'orders_to_deliver')<td>{{ $o['team'] }}</td>@endif
                            <td>{{ $o['partner'] }}</td>
                            <td>@foreach ($o['lines'] as $l){{ $l['qty'] }} × {{ $l['number'] }} {{ $l['name'] }}@if (! $loop->last), @endif @endforeach</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="section">
        <h2>Team drivers (Driver Portal logins)</h2>
        <table>
            <thead><tr><th>Team</th><th>Driver</th><th>Phone</th><th>PIN</th></tr></thead>
            <tbody>
                @foreach ($summary['team_drivers'] as $d)
                    <tr><td>{{ $d['team'] }}</td><td>{{ $d['name'] }}</td><td>{{ $d['phone'] }}</td><td>{{ $d['pin'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <p class="note">Order Entry (Station 6, Round 1) uses the training partners: {{ implode(', ', $summary['partners']) }}.</p>
    </div>
</body>
</html>
