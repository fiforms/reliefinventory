@php
    $range = match (true) {
        ! empty($filters['from']) && ! empty($filters['to']) => 'orders dated '.$filters['from'].' to '.$filters['to'],
        ! empty($filters['from']) => 'orders dated '.$filters['from'].' or later',
        ! empty($filters['to']) => 'orders dated '.$filters['to'].' or earlier',
        default => 'all orders',
    };
    $totals = $report['totals'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unfulfilled Requests Report</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Unfulfilled Requests Report — generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}";
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
            margin-top: 0.75in;
            @top-left { content: none; }
            @top-right { content: none; }
        }
        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 0;
        }
        h1 {
            font-size: 24px;
            margin: 0 0 2px 0;
        }
        h2 {
            font-size: 14px;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 4px;
            margin: 22px 0 8px 0;
            color: #1e3a8a;
        }
        .subtitle {
            color: #666;
            font-size: 13px;
            margin-bottom: 14px;
        }
        .summary {
            font-size: 12px;
        }
        .summary strong {
            font-size: 14px;
        }
        /* Flex items must be plain blocks: weasyprint mis-sizes tables used directly as flex items. */
        .columns {
            display: flex;
            gap: 18px;
        }
        .columns > div {
            flex: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        thead {
            display: table-header-group;
        }
        tr {
            break-inside: avoid;
        }
        th, td {
            text-align: left;
            padding: 3px 6px;
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
        tr.item td {
            font-weight: bold;
            border-top: 1px solid #ccc;
        }
        tr.loc td {
            color: #555;
            font-size: 10px;
        }
        tr.loc td.place {
            padding-left: 22px;
        }
        .short {
            color: #b91c1c;
        }
        .disclaimer {
            font-size: 10.5px;
            color: #888;
            font-style: italic;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            margin-top: 22px;
        }
    </style>
</head>
<body>
    <h1>Unfulfilled Requests Report</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; what was requested but not filled, {{ $range }}, generated
        {{ $generatedAt->format('F j, Y \a\t g:i A T') }}
    </div>

    <div class="summary">
        <strong>{{ $totals['item_types'] }}</strong> item types short across
        <strong>{{ $totals['requests'] }}</strong> request{{ $totals['requests'] === 1 ? '' : 's' }}:
        <strong>{{ number_format($totals['requested']) }}</strong> requested,
        <strong>{{ number_format($totals['filled']) }}</strong> filled,
        <strong class="short">{{ number_format($totals['not_filled']) }}</strong> not filled
        ({{ number_format($totals['cut_at_allocation']) }} cut at allocation,
        {{ number_format($totals['short_at_picking']) }} short at picking).
    </div>

    @if($report['items']->isEmpty())
        <p>No unfulfilled requests{{ $range === 'all orders' ? '' : ' in this date range' }}.</p>
    @else
        <div class="columns">
            @foreach(['county' => 'County', 'zip' => 'Zip'] as $field => $label)
                <div>
                    <h2>Not Filled by {{ $label }}</h2>
                    <table>
                        <thead><tr>
                            <th>{{ $label }}</th><th class="num">Requests</th><th class="num">Item Types</th><th class="num">Not Filled</th>
                        </tr></thead>
                        <tbody>
                            @foreach($report['by_'.$field] as $row)
                                <tr>
                                    <td>{{ $row[$field] }}</td>
                                    <td class="num">{{ $row['requests'] }}</td>
                                    <td class="num">{{ $row['item_types'] }}</td>
                                    <td class="num">{{ number_format($row['not_filled']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>

        <h2>Items Not Filled</h2>
        <table>
            <thead>
                <tr>
                    <th>Item #</th>
                    <th>Item / County &middot; Zip</th>
                    <th>Unit</th>
                    <th class="num">Requests</th>
                    <th class="num">Requested</th>
                    <th class="num">Filled</th>
                    <th class="num">Not Filled</th>
                    <th class="num">Cut at Allocation</th>
                    <th class="num">Short at Picking</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['items'] as $item)
                    <tr class="item">
                        <td>{{ $item['display_number'] ?? '—' }}</td>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ $item['unit'] }}</td>
                        <td class="num">{{ $item['requests'] }}</td>
                        <td class="num">{{ number_format($item['requested']) }}</td>
                        <td class="num">{{ number_format($item['filled']) }}</td>
                        <td class="num short">{{ number_format($item['not_filled']) }}</td>
                        <td class="num">{{ number_format($item['cut_at_allocation']) }}</td>
                        <td class="num">{{ number_format($item['short_at_picking']) }}</td>
                    </tr>
                    @foreach($item['locations'] as $loc)
                        <tr class="loc">
                            <td></td>
                            <td class="place">{{ $loc['county'] }} &middot; {{ $loc['zip'] }}</td>
                            <td></td>
                            <td class="num">{{ $loc['requests'] }}</td>
                            <td class="num">{{ number_format($loc['requested']) }}</td>
                            <td class="num">{{ number_format($loc['filled']) }}</td>
                            <td class="num">{{ number_format($loc['not_filled']) }}</td>
                            <td class="num">{{ number_format($loc['cut_at_allocation']) }}</td>
                            <td class="num">{{ number_format($loc['short_at_picking']) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="disclaimer">
        Counts only requests whose filling is finished (Filled through Completed, plus Closed &ndash; Unfulfilled);
        requests still being entered or filled aren't included. Not filled = requested &minus; filled. Cut at
        allocation = less approved than requested in Review Allocation; short at picking = approved but not picked.
        County and zip are the requesting partner's. Quantities are in each item's own unit, so totals across
        different items mix units.
    </div>
</body>
</html>
