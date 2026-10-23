{{-- Training Mode setup packet (TrainingAdminController::setupPacketBuilder): what the last Prepare Training loaded, so
     trainers can stage the floor to match — the offers Station 1 matches the trainer's own goods to,
     where each scanned item goes for Stations 3–5 (a placement list, and a contents slip per spot / load to cut apart), and
     a plain sign per station. $summary =
     training_settings.prepared_summary; $stations = TrainingMode::STATIONS. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Training Setup Packet</title>
    <style>
        @page { size: letter; margin: 0.5in 0.55in; }
        @page sign { size: letter landscape; margin: 0.5in; }
        body { font-family: Arial, sans-serif; color: #222; margin: 0; font-size: 11px; line-height: 1.4; }
        h1 { font-size: 22px; margin: 0; }
        .subtitle { color: #666; margin: 2px 0 12px 0; }
        h2 { font-size: 14px; color: #1e3a8a; border-bottom: 2px solid #1e3a8a; padding-bottom: 2px; margin: 14px 0 6px 0; }
        h3 { font-size: 12px; margin: 8px 0 3px 0; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 6px; }
        th, td { border: 1px solid #bbb; padding: 3px 6px; text-align: left; vertical-align: top; }
        th { background: #eef2ff; }
        td.num, th.num { text-align: right; width: 50px; }
        .note { color: #555; }
        .warning { color: #9a3412; font-weight: bold; }
        .section { page-break-inside: avoid; }
        .new-page { page-break-before: always; }
        .slip { border: 2px dashed #888; padding: 10px 14px; margin-bottom: 14px; page-break-inside: avoid; }
        .slip-station { font-size: 12px; color: #555; text-transform: uppercase; letter-spacing: 1px; }
        .slip-title { font-size: 24px; font-weight: bold; margin: 2px 0 6px 0; }
        .slip table { font-size: 13px; }
        .sign { page: sign; page-break-before: always; text-align: center; padding-top: 1.4in; }
        .sign-number { font-size: 150px; font-weight: bold; line-height: 1; }
        .sign-name { font-size: 90px; margin-top: 30px; line-height: 1; }
    </style>
</head>
<body>
    @php
        $itemRows = function (array $items) {
            return collect($items)->map(fn ($i) => ['what' => $i['name'], 'acs' => trim($i['number'].' '.$i['type']), 'qty' => $i['qty']]);
        };
        $slips = [];
        $slips[] = ['station' => 3, 'title' => 'Sorting area table', 'sub' => 'Already sorted — build new pallets from these', 'items' => $summary['sorting_area']];
        foreach ($summary['warehouse_pallets'] as $p) {
            $slips[] = ['station' => 4, 'title' => 'Spot '.$p['location'].' — pallet '.$p['tag'], 'sub' => 'Put pallet label '.$p['tag'].' on this tray', 'items' => $p['items']];
        }
        foreach ($summary['orders_to_ship'] as $o) {
            $slips[] = ['station' => 5, 'title' => 'Order #'.$o['id'].' — ready to ship', 'sub' => $o['partner'], 'items' => $o['items']];
        }
    @endphp

    <h1>Training Setup Packet</h1>
    <div class="subtitle">{{ config('app.name') }} — prepared {{ \Carbon\Carbon::parse($summary['prepared_at'])->format('F j, Y g:i A') }}
        — {{ $summary['products']['units'] }} scanned items, {{ $summary['products']['distinct'] }} different products</div>

    @foreach ($summary['warnings'] ?? [] as $warning)
        <p class="warning">{{ $warning }}</p>
    @endforeach

    <div class="section">
        <h2>How it works</h2>
        <p>Only the first team at each station needs staged goods: teams move from station N to N+1, so after its first stop
            every team works on what it handled itself one station earlier. Receiving uses your own goods (not scanned): put out
            boxes to receive, and the teams sort what they bring in. Stations 3–5 hold the scanned goods, placed
            as listed below. When a team marks a load delivered (Station 6, Round 2), carry that box back to Receiving as a new delivery.</p>
        <table>
            <thead><tr><th>Station</th><th>Put out</th><th class="num">Items</th></tr></thead>
            <tbody>
                <tr><td>1 {{ $stations[1] }}</td><td>Boxes of your own goods to receive ({{ count($summary['offers']) }} offers waiting for them)</td><td class="num">yours</td></tr>
                <tr><td>2 {{ $stations[2] }}</td><td>Nothing — the teams sort what they received</td><td class="num">0</td></tr>
                <tr><td>3 {{ $stations[3] }}</td><td>Sorted goods on the sorting area table</td><td class="num">{{ collect($summary['sorting_area'])->sum('qty') }}</td></tr>
                <tr><td>4 {{ $stations[4] }}</td><td>{{ count($summary['warehouse_pallets']) }} sealed trays at table spots, {{ count($summary['orders_to_fill']) }} orders to pick</td><td class="num">{{ collect($summary['warehouse_pallets'])->flatMap(fn ($p) => $p['items'])->sum('qty') }}</td></tr>
                <tr><td>5 {{ $stations[5] }}</td><td>{{ count($summary['orders_to_ship']) }} picked orders at the door</td><td class="num">{{ collect($summary['orders_to_ship'])->flatMap(fn ($o) => $o['items'])->sum('qty') }}</td></tr>
                <tr><td>6 {{ $stations[6] }}</td><td>Nothing — each team delivers the load it shipped</td><td class="num">0</td></tr>
            </tbody>
        </table>
        <p class="note">Tape the table spot labels (Setup &gt; Training Mode &gt; Table Spot Labels) on the tables: {{ implode(', ', $summary['locations']) }}.
            Print the pallet labels too, for the Station 4 trays. The contents slips (Stations 3–5) and station signs are at the back of this packet.</p>
    </div>

    <div class="section">
        <h2>Station 1 — {{ $stations[1] }}: offers waiting for arrival</h2>
        <p class="note">Put out boxes of your own goods to receive; the receiving team matches each delivery to one of these phoned-in offers.</p>
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
        <h2>Station 3 — {{ $stations[3] }}: sorting area table</h2>
        <p class="note">Already sorted, not on a pallet yet — the first team builds a pallet from these and puts it on a table spot.</p>
        <table>
            <tbody>@foreach ($itemRows($summary['sorting_area']) as $r)<tr><td>{{ $r['what'] }}</td><td>{{ $r['acs'] }}</td><td class="num">{{ $r['qty'] }}</td></tr>@endforeach</tbody>
        </table>
    </div>

    <div class="section">
        <h2>Station 4 — {{ $stations[4] }}: sealed trays on the tables</h2>
        @foreach ($summary['warehouse_pallets'] as $p)
            <h3>Spot {{ $p['location'] }} — pallet {{ $p['tag'] }}</h3>
            <table>
                <tbody>@foreach ($itemRows($p['items']) as $r)<tr><td>{{ $r['what'] }}</td><td>{{ $r['acs'] }}</td><td class="num">{{ $r['qty'] }}</td></tr>@endforeach</tbody>
            </table>
        @endforeach
        <h3>Orders waiting to be picked (Ready to Fill)</h3>
        <table>
            <thead><tr><th>Order #</th><th>Partner</th><th>Lines</th></tr></thead>
            <tbody>
                @foreach ($summary['orders_to_fill'] as $o)
                    <tr><td>{{ $o['id'] }}</td><td>{{ $o['partner'] }}</td>
                        <td>@foreach ($o['lines'] as $l){{ $l['qty'] }} × {{ $l['number'] }} {{ $l['name'] }}@if (! $loop->last), @endif @endforeach</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Station 5 — {{ $stations[5] }}: picked orders at the door</h2>
        @foreach ($summary['orders_to_ship'] as $o)
            <h3>Order #{{ $o['id'] }} — {{ $o['partner'] }}</h3>
            <table>
                <tbody>@foreach ($itemRows($o['items']) as $r)<tr><td>{{ $r['what'] }}</td><td>{{ $r['acs'] }}</td><td class="num">{{ $r['qty'] }}</td></tr>@endforeach</tbody>
            </table>
        @endforeach
    </div>

    <div class="section">
        <h2>Station 6 — {{ $stations[6] }}: team drivers (Driver Portal logins)</h2>
        <p class="note">In Round 2 each team delivers the load it shipped to its own driver at Station 5.</p>
        <table>
            <thead><tr><th>Team</th><th>Driver</th><th>Phone</th><th>PIN</th></tr></thead>
            <tbody>
                @foreach ($summary['team_drivers'] as $d)
                    <tr><td>{{ $d['team'] }}</td><td>{{ $d['name'] }}</td><td>{{ $d['phone'] }}</td><td>{{ $d['pin'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <p class="note">Order Entry (Round 1) uses the training partners: {{ implode(', ', $summary['partners']) }}.</p>
    </div>

    {{-- Contents slips: cut apart; one goes in each box / on each tray. --}}
    <div class="new-page">
        <h2>Contents slips — cut apart, one with each spot and load</h2>
        @foreach ($slips as $slip)
            <div class="slip">
                <div class="slip-station">Station {{ $slip['station'] }} — {{ $stations[$slip['station']] }}</div>
                <div class="slip-title">{{ $slip['title'] }}</div>
                <div class="note">{{ $slip['sub'] }}</div>
                <table>
                    <tbody>@foreach ($itemRows($slip['items']) as $r)<tr><td>{{ $r['what'] }}</td><td>{{ $r['acs'] }}</td><td class="num">{{ $r['qty'] }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        @endforeach
    </div>

    @foreach ($stations as $number => $name)
        <div class="sign">
            <div class="sign-number">Station {{ $number }}</div>
            <div class="sign-name">{{ $name }}</div>
        </div>
    @endforeach
</body>
</html>
