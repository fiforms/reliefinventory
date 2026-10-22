@php
    $f = $report['filters'];
    $t = $report['totals'];
    $signed = fn ($n) => ($n > 0 ? '+' : '').number_format($n);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Flow Report</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Inventory Flow Report — generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}";
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
        .disclaimer { font-size: 10.5px; color: #888; font-style: italic; border-top: 1px solid #ddd; padding-top: 6px; margin-top: 22px; }
    </style>
</head>
<body>
    <h1>Inventory Flow Report</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; {{ $f->from }} to {{ $f->to }}, by {{ $f->bucket }}, generated
        {{ $generatedAt->format('F j, Y \a\t g:i A T') }}
    </div>

    <div class="summary">
        Opening on hand <strong>{{ number_format($t['opening']) }}</strong>
        + received <strong>{{ number_format($t['received']) }}</strong>
        &minus; picked <strong>{{ number_format($t['picked']) }}</strong>
        {{ $t['adjustments'] < 0 ? '&minus;' : '+' }} adjustments <strong>{{ number_format(abs($t['adjustments'])) }}</strong>
        = closing on hand <strong>{{ number_format($t['closing']) }}</strong>.
        Not stock: {{ number_format($t['outdated']) }} outdated, {{ number_format($t['trashed']) }} trashed,
        {{ number_format($t['diverted']) }} diverted.
    </div>

    <h2>By {{ $f->bucket }}</h2>
    <table>
        <thead><tr>
            <th>Period starting</th><th class="num">Received</th><th class="num">Picked</th><th class="num">Adjustments</th>
            <th class="num">On hand (end)</th><th class="num">Outdated</th><th class="num">Trashed</th><th class="num">Diverted</th>
        </tr></thead>
        <tbody>
            @foreach($report['buckets'] as $b)
                <tr>
                    <td>{{ $b['start'] }}</td>
                    <td class="num">{{ number_format($b['received']) }}</td>
                    <td class="num">{{ number_format($b['picked']) }}</td>
                    <td class="num">{{ $signed($b['adjustments']) }}</td>
                    <td class="num">{{ number_format($b['on_hand']) }}</td>
                    <td class="num">{{ number_format($b['outdated']) }}</td>
                    <td class="num">{{ number_format($b['trashed']) }}</td>
                    <td class="num">{{ number_format($b['diverted']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(count($report['adjustment_reasons']))
        <h2>Adjustments by reason</h2>
        <table>
            <thead><tr><th>Reason</th><th class="num">Added</th><th class="num">Removed</th></tr></thead>
            <tbody>
                @foreach($report['adjustment_reasons'] as $r)
                    <tr><td>{{ $r['label'] }}</td><td class="num">{{ number_format($r['added']) }}</td><td class="num">{{ number_format($r['removed']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>By item</h2>
    <table>
        <thead><tr>
            <th>Item #</th><th>Item</th><th>Unit</th><th class="num">Opening</th><th class="num">Received</th>
            <th class="num">Picked</th><th class="num">Adjust.</th><th class="num">Closing</th>
            <th class="num">Outdated</th><th class="num">Trashed</th><th class="num">Diverted</th>
        </tr></thead>
        <tbody>
            @foreach($report['items'] as $i)
                <tr>
                    <td>{{ $i['display_number'] ?? '—' }}</td>
                    <td>{{ $i['name'] }}</td>
                    <td>{{ $i['unit'] }}</td>
                    <td class="num">{{ number_format($i['opening']) }}</td>
                    <td class="num">{{ number_format($i['received']) }}</td>
                    <td class="num">{{ number_format($i['picked']) }}</td>
                    <td class="num">{{ $signed($i['adjustments']) }}</td>
                    <td class="num">{{ number_format($i['closing']) }}</td>
                    <td class="num">{{ number_format($i['outdated']) }}</td>
                    <td class="num">{{ number_format($i['trashed']) }}</td>
                    <td class="num">{{ number_format($i['diverted']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="disclaimer">
        Received = usable goods sorted in; picked = filled onto requests; adjustments = manual inventory corrections
        (net). Outdated, trashed and diverted goods are not stock and don't change on-hand. Moving a pallet changes
        where stock is, not how much, so it isn't shown. Quantities are in each item's own unit, so totals across
        different items mix units.
    </div>
</body>
</html>
