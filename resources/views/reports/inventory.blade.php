<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Report</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Inventory Report — generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}";
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
            margin-top: 22px;
        }
        thead {
            display: table-header-group;
        }
        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        th.category-heading {
            font-size: 14px;
            color: #1e3a8a;
            border-bottom: 2px solid #1e3a8a;
            padding: 0 8px 6px 8px;
            font-weight: bold;
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
        td.num {
            font-weight: bold;
        }
        .loss {
            color: #b91c1c;
            font-weight: normal;
        }
        .totals td {
            font-weight: bold;
            border-top: 2px solid #ccc;
            border-bottom: none;
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
    <h1>Inventory Report</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; stock on hand, generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}
    </div>

    @foreach($records->groupBy('category') as $category => $rows)
        <table>
            <thead>
                <tr>
                    <th class="category-heading" colspan="7">{{ $category ?? 'Uncategorized' }}</th>
                </tr>
                <tr>
                    <th>Item #</th>
                    <th>Name</th>
                    <th>Unit</th>
                    <th class="num">On Hand</th>
                    <th class="num">Outdated</th>
                    <th class="num">Trashed</th>
                    <th class="num">Diverted</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['display_number'] ?? '(unnumbered)' }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['unit'] }}</td>
                        <td class="num">{{ $row['on_hand'] }}</td>
                        <td class="num loss">{{ $row['outdated'] ?: '' }}</td>
                        <td class="num loss">{{ $row['trashed'] ?: '' }}</td>
                        <td class="num">{{ $row['diverted'] ?: '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    @if($records->isEmpty())
        <p>No stock on hand yet.</p>
    @else
        <table>
            <tbody>
                <tr class="totals">
                    <td colspan="3">Totals ({{ $records->count() }} item type{{ $records->count() === 1 ? '' : 's' }})</td>
                    <td class="num">{{ $records->sum('on_hand') }}</td>
                    <td class="num">{{ $records->sum('outdated') ?: '' }}</td>
                    <td class="num">{{ $records->sum('trashed') ?: '' }}</td>
                    <td class="num">{{ $records->sum('diverted') ?: '' }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="disclaimer">
        Generated at the moment of viewing from currently recorded ledger activity. Only item types with recorded
        usable, outdated, trashed, or diverted quantity are listed; the full catalog is larger.
    </div>
</body>
</html>
