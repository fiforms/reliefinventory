<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Outstanding Orders Report</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Outstanding Orders Report — generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}";
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
        }
        h1 {
            font-size: 24px;
            margin: 0 0 2px 0;
        }
        .subtitle {
            color: #666;
            font-size: 13px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }
        thead {
            display: table-header-group;
        }
        tr.order-row {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        th, td {
            text-align: left;
            padding: 4px 8px;
            border-bottom: 1px solid #eee;
        }
        th {
            color: #666;
            font-weight: normal;
            border-bottom: 2px solid #ccc;
        }
        td.num, th.num {
            text-align: right;
        }
        .lines {
            font-size: 10.5px;
            color: #555;
            padding: 0 8px 8px 24px;
            border-bottom: 1px solid #eee;
        }
        .lines span {
            margin-right: 14px;
        }
        .status-badge {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #92400e;
            background: #fef3c7;
            padding: 1px 5px;
            border-radius: 6px;
        }
        .disclaimer {
            font-size: 11px;
            color: #888;
            font-style: italic;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            margin-top: 26px;
        }
    </style>
</head>
<body>
    <h1>Outstanding Orders Report</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; every order not yet shipped, generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Order #</th>
                <th>Partner</th>
                <th>Status</th>
                <th>Order Date</th>
                <th>Needed By</th>
                <th>Fulfillment</th>
                <th class="num">Lines</th>
                <th class="num">Qty Requested</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $order)
                <tr class="order-row">
                    <td>{{ $order['id'] }}</td>
                    <td>{{ $order['partner'] ?? '(no partner)' }}</td>
                    <td><span class="status-badge">{{ $order['status'] }}</span></td>
                    <td>{{ $order['order_date'] }}</td>
                    <td>{{ $order['needed_by_date'] ?: '—' }}</td>
                    <td>{{ $order['fulfillment_method'] }}</td>
                    <td class="num">{{ $order['line_count'] }}</td>
                    <td class="num">{{ $order['qty_requested'] }}</td>
                </tr>
                @if($order['lines']->isNotEmpty())
                    <tr>
                        <td colspan="8" class="lines">
                            @foreach($order['lines'] as $line)
                                <span>{{ $line['display_number'] ?? '(unnumbered)' }} {{ $line['itemtype'] }} &times; {{ $line['qty_requested'] }} {{ $line['unit'] }}</span>
                            @endforeach
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="8">No outstanding orders.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="disclaimer">
        Includes every order in New Order, Ready to Fill, Filling, or Filled status — anything not yet Shipped.
    </div>
</body>
</html>
