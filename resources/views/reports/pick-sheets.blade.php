<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pick Sheets</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Pick Sheet — generated {{ $generatedAt->format('F j, Y g:ia') }}";
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
            font-size: 20px;
            margin: 0 0 2px 0;
        }
        .subtitle {
            color: #666;
            font-size: 12px;
            margin-bottom: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 12px;
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
            width: 90px;
            text-align: center;
        }
        th.picked, td.picked {
            width: 60px;
            text-align: center;
        }
        td.picked {
            border: 1px solid #bbb;
            border-radius: 3px;
            height: 20px;
        }
        .signoff {
            margin-top: 30px;
            font-size: 11px;
        }
        .signoff .line {
            display: inline-block;
            border-bottom: 1px solid #999;
            width: 220px;
            height: 18px;
            margin-right: 8px;
        }
        .order-page {
            page-break-after: always;
        }
        .order-page:last-child {
            page-break-after: auto;
        }
    </style>
</head>
<body>
@foreach($orders as $order)
    <div class="order-page">
        <h1>Pick Sheet — Order #{{ $order->id }}</h1>
        <div class="subtitle">
            {{ $order->person?->full_name ?? 'Unknown' }}
            @if($order->order_date) &mdash; {{ \Illuminate\Support\Carbon::parse($order->order_date)->format('F j, Y') }} @endif
        </div>

        <table>
            <thead>
                <tr>
                    <th>Item #</th>
                    <th>Name</th>
                    <th>Unit</th>
                    <th class="qty">Qty Requested</th>
                    <th class="picked">Picked</th>
                </tr>
            </thead>
            <tbody>
                @forelse($order->orderLines as $line)
                    <tr>
                        <td>{{ $line->itemtype?->display_number ?? '' }}</td>
                        <td>{{ $line->itemtype?->name }}</td>
                        <td>{{ $line->itemtype?->unit?->abbreviation ?? $line->itemtype?->unit?->name }}</td>
                        <td class="qty">{{ $line->qty_requested }}</td>
                        <td class="picked"></td>
                    </tr>
                @empty
                    <tr><td colspan="5">No lines on this order.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="signoff">
            Pulled by: <span class="line"></span> Date: <span class="line" style="width:120px;"></span>
        </div>
    </div>
@endforeach
</body>
</html>
