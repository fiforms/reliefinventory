<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $order->bol_number }}</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "{{ $order->bol_number }} — generated {{ $generatedAt->format('F j, Y g:ia') }}";
                font-family: Arial, sans-serif;
                font-size: 9px;
                color: #888;
            }
            @top-right {
                content: "Page " counter(page) " of " counter(pages);
                font-family: Arial, sans-serif;
                font-size: 9px;
                color: #888;
            }
        }
        @page :first {
            margin-top: 0.5in;
            @top-left { content: none; }
            @top-right { content: none; }
        }
        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 0;
            padding: 0;
            font-size: 12px;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 2px 0;
        }
        .subtitle {
            color: #666;
            font-size: 12px;
            margin-bottom: 16px;
        }
        .section {
            margin-top: 16px;
        }
        .section h2 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #666;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
            margin: 0 0 8px 0;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 24px;
        }
        .field {
            margin-bottom: 6px;
        }
        .field .label {
            color: #666;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .field .value {
            border-bottom: 1px solid #ccc;
            min-height: 16px;
            padding-bottom: 2px;
        }
        .field .value.blank {
            /* left for hand-entry: carrier/driver assignment isn't captured
               by the app yet — see order-fulfillment-lifecycle-design */
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 8px;
        }
        thead {
            display: table-header-group;
        }
        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        th, td {
            text-align: left;
            padding: 5px 6px;
            border-bottom: 1px solid #eee;
        }
        th {
            color: #666;
            font-weight: normal;
            border-bottom: 2px solid #ccc;
        }
        th.qty, td.qty {
            width: 100px;
            text-align: center;
        }
        .instructions {
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 8px;
            min-height: 40px;
            white-space: pre-wrap;
        }
        .signoff {
            margin-top: 26px;
            break-inside: avoid;
        }
        .signoff h2 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #666;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
            margin: 0 0 8px 0;
        }
        .signoff .row {
            display: flex;
            gap: 24px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .signoff .line {
            display: inline-block;
            border-bottom: 1px solid #999;
            min-width: 180px;
            height: 18px;
            margin-left: 6px;
        }
    </style>
</head>
<body>
    <h1>Bill of Lading</h1>
    <div class="subtitle">{{ $order->bol_number }} &mdash; Order #{{ $order->id }}</div>

    <div class="section">
        <h2>Shipment</h2>
        <div class="grid">
            <div class="field">
                <div class="label">Ship To</div>
                <div class="value">{{ $order->person?->full_name ?? 'Unknown' }}</div>
            </div>
            <div class="field">
                <div class="label">Delivery Address</div>
                <div class="value">
                    @if($order->person?->address || $order->person?->city)
                        {{ $order->person->address }}, {{ $order->person->city }}, {{ $order->person->state }} {{ $order->person->zip }}
                    @else
                        &nbsp;
                    @endif
                </div>
            </div>
            <div class="field">
                <div class="label">Fulfillment Method</div>
                <div class="value">{{ ucfirst($order->fulfillment_method ?? '') }}</div>
            </div>
            <div class="field">
                <div class="label">Contact</div>
                <div class="value">{{ $order->contact_name }} @if($order->contact_phone) &mdash; {{ $order->contact_phone }} @endif</div>
            </div>
            <div class="field">
                <div class="label">Requested Date</div>
                <div class="value">{{ $order->needed_by_date ? \Illuminate\Support\Carbon::parse($order->needed_by_date)->format('F j, Y') : '' }}</div>
            </div>
            <div class="field">
                <div class="label">Delivery Window</div>
                <div class="value">{{ $order->preferred_time }}</div>
            </div>
            <div class="field">
                <div class="label">Delivery Date</div>
                <div class="value blank">&nbsp;</div>
            </div>
            <div class="field">
                <div class="label">Pallet Count (as packed)</div>
                <div class="value">{{ $order->pallet_count ?? '' }}</div>
            </div>
            <div class="field">
                <div class="label">Pallet Count (confirmed by driver)</div>
                <div class="value blank">&nbsp;</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Special Instructions</h2>
        <div class="instructions">{{ $order->special_instructions ?: '—' }}</div>
    </div>

    <div class="section">
        <h2>Items</h2>
        <table>
            <thead>
                <tr>
                    <th>Item #</th>
                    <th>Name</th>
                    <th>Unit</th>
                    <th class="qty">Qty Filled</th>
                </tr>
            </thead>
            <tbody>
                @forelse($order->orderLines as $line)
                    <tr>
                        <td>{{ $line->itemtype?->display_number ?? '' }}</td>
                        <td>{{ $line->itemtype?->name }}</td>
                        <td>{{ $line->itemtype?->unit?->abbreviation ?? $line->itemtype?->unit?->name }}</td>
                        <td class="qty">{{ $line->itemLedgers->sum('qty_subtracted') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No lines on this order.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="signoff">
        <h2>Shipper Release</h2>
        <div class="row">
            Staff Name: <span class="line"></span>
            Signature: <span class="line"></span>
            Date: <span class="line" style="min-width:100px;"></span>
        </div>
    </div>

    <div class="signoff">
        <h2>Carrier / Driver Receipt</h2>
        <div class="row">
            Carrier: <span class="line">{{ $order->driver?->carrier }}</span>
            Driver Name: <span class="line">{{ $order->driver?->name }}</span>
            Driver Cell: <span class="line">{{ $order->driver?->phone }}</span>
        </div>
        <div class="row">
            Driver Signature: <span class="line"></span>
            Pickup Date: <span class="line" style="min-width:100px;"></span>
        </div>
    </div>

    <div class="signoff">
        <h2>Delivery Confirmation</h2>
        <div class="row">
            Received By (print): <span class="line"></span>
            Signature: <span class="line"></span>
            Date: <span class="line" style="min-width:100px;"></span>
        </div>
    </div>
</body>
</html>
