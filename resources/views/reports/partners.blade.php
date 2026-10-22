@php
    $f = $report['filters'];
    $t = $report['totals'];
    $rate = fn ($v) => $v === null ? '—' : $v.'%';
    $range = match (true) {
        ! empty($f->from) && ! empty($f->to) => 'orders dated '.$f->from.' to '.$f->to,
        ! empty($f->from) => 'orders dated '.$f->from.' or later',
        ! empty($f->to) => 'orders dated '.$f->to.' or earlier',
        default => 'all orders',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Partner Report</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Partner Report — generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}";
                font-family: Arial, sans-serif; font-size: 9px; color: #888;
            }
            @top-right {
                content: "Page " counter(page) " of " counter(pages);
                font-family: Arial, sans-serif; font-size: 9px; color: #888;
            }
        }
        @page :first {
            margin-top: 0.75in;
            @top-left { content: none; }
            @top-right { content: none; }
        }
        body { font-family: Arial, sans-serif; color: #222; margin: 0; }
        h1 { font-size: 24px; margin: 0 0 2px 0; }
        h2 { font-size: 14px; border-bottom: 2px solid #1e3a8a; padding-bottom: 4px; margin: 22px 0 8px 0; color: #1e3a8a; }
        .subtitle { color: #666; font-size: 13px; margin-bottom: 14px; }
        .summary { font-size: 12px; }
        .summary strong { font-size: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { text-align: left; padding: 3px 6px; border-bottom: 1px solid #eee; }
        th { color: #666; font-weight: normal; border-bottom: 2px solid #ccc; }
        td.num, th.num { text-align: right; }
        tr.partner td { font-weight: bold; border-top: 1px solid #ccc; }
        tr.order td { color: #555; font-size: 10px; }
        tr.order td.indent { padding-left: 22px; }
        .disclaimer { font-size: 10.5px; color: #888; font-style: italic; border-top: 1px solid #ddd; padding-top: 6px; margin-top: 22px; }
    </style>
</head>
<body>
    <h1>Partner Report</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; what each partner requested and received, {{ $range }}, generated
        {{ $generatedAt->format('F j, Y \a\t g:i A T') }}
    </div>

    <div class="summary">
        <strong>{{ $t['partners'] }}</strong> partner{{ $t['partners'] === 1 ? '' : 's' }},
        <strong>{{ $t['requests'] }}</strong> request{{ $t['requests'] === 1 ? '' : 's' }}:
        <strong>{{ number_format($t['requested']) }}</strong> requested,
        <strong>{{ number_format($t['filled']) }}</strong> filled
        (fill rate on finished requests {{ $rate($t['fill_rate']) }});
        {{ $t['shipped'] }} shipped, {{ $t['delivered'] }} delivered.
    </div>

    @if($report['partners']->isEmpty())
        <p>No requests{{ $range === 'all orders' && empty($f->county_id) ? '' : ' for these filters' }}.</p>
    @else
        <h2>By County</h2>
        <table>
            <thead><tr><th>County</th><th class="num">Partners</th><th class="num">Requests</th><th class="num">Requested</th><th class="num">Filled</th></tr></thead>
            <tbody>
                @foreach($report['by_county'] as $row)
                    <tr>
                        <td>{{ $row['county'] }}</td>
                        <td class="num">{{ $row['partners'] }}</td>
                        <td class="num">{{ $row['requests'] }}</td>
                        <td class="num">{{ number_format($row['requested']) }}</td>
                        <td class="num">{{ number_format($row['filled']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Partners</h2>
        <table>
            <thead>
                <tr>
                    <th>Partner / Request</th><th>County &middot; Zip</th><th class="num">Requests</th>
                    <th class="num">Requested</th><th class="num">Filled</th><th class="num">Fill Rate</th>
                    <th class="num">Shipped</th><th class="num">Delivered</th><th>Last Request</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['partners'] as $p)
                    <tr class="partner">
                        <td>{{ $p['name'] }}</td>
                        <td>{{ $p['county'] }} &middot; {{ $p['zip'] }}</td>
                        <td class="num">{{ $p['requests'] }}</td>
                        <td class="num">{{ number_format($p['requested']) }}</td>
                        <td class="num">{{ number_format($p['filled']) }}</td>
                        <td class="num">{{ $rate($p['fill_rate']) }}</td>
                        <td class="num">{{ $p['shipped'] }}</td>
                        <td class="num">{{ $p['delivered'] }}</td>
                        <td>{{ $p['last_request'] }}</td>
                    </tr>
                    @foreach($p['orders'] as $o)
                        <tr class="order">
                            <td class="indent">#{{ $o['id'] }} &middot; {{ $o['status'] }}</td>
                            <td>{{ $o['order_date'] }}</td>
                            <td></td>
                            <td class="num">{{ number_format($o['requested']) }}</td>
                            <td class="num">{{ number_format($o['filled']) }}</td>
                            <td></td><td></td><td></td><td></td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="disclaimer">
        Every request counts toward requests and requested, whatever its status. Fill rate = filled &divide;
        requested over requests whose filling is finished. County and zip are the partner's. Quantities are in each
        item's own unit, so totals mix units. See the Unfulfilled Requests Report for what was short and why.
    </div>
</body>
</html>
