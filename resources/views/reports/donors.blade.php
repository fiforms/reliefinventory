@php
    $f = $report['filters'];
    $t = $report['totals'];
    $c = $report['thresholds'];
    $pct = fn ($v) => $v === null ? '—' : $v.'%';
    $range = match (true) {
        ! empty($f->from) && ! empty($f->to) => 'donations dated '.$f->from.' to '.$f->to,
        ! empty($f->from) => 'donations dated '.$f->from.' or later',
        ! empty($f->to) => 'donations dated '.$f->to.' or earlier',
        default => 'all donations',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Donor Report</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Donor Report — generated {{ $generatedAt->format('F j, Y \a\t g:i A T') }}";
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
        tr.donor td { font-weight: bold; border-top: 1px solid #ccc; }
        tr.donation td { color: #555; font-size: 10px; }
        tr.donation td.indent { padding-left: 22px; }
        .muted { color: #888; font-weight: normal; }
        .disclaimer { font-size: 10.5px; color: #888; font-style: italic; border-top: 1px solid #ddd; padding-top: 6px; margin-top: 22px; }
    </style>
</head>
<body>
    <h1>Donor Report</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; what each donor sent and how much was usable, {{ $range }}, generated
        {{ $generatedAt->format('F j, Y \a\t g:i A T') }}
    </div>

    <div class="summary">
        <strong>{{ $t['donors'] }}</strong> donor{{ $t['donors'] === 1 ? '' : 's' }},
        <strong>{{ $t['donations'] }}</strong> sorted donation{{ $t['donations'] === 1 ? '' : 's' }},
        <strong>{{ number_format($t['units']) }}</strong> units sorted, overall loss <strong>{{ $pct($t['loss_percent']) }}</strong>.
        {{ $t['watch'] }} on watch, {{ $t['decline'] }} on decline; {{ $t['needs_review'] }} with a suggestion that differs from their standing.
    </div>

    @if($report['donors']->isEmpty())
        <p>No donations{{ $range === 'all donations' ? '' : ' in this date range' }}.</p>
    @else
        <h2>Donors</h2>
        <table>
            <thead>
                <tr>
                    <th>Donor / Donation</th><th>Standing</th><th>Suggested</th><th class="num">Donations</th>
                    <th class="num">Units</th><th class="num">Usable</th><th class="num">Outdated</th>
                    <th class="num">Trashed</th><th class="num">Diverted</th><th class="num">Loss %</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['donors'] as $d)
                    <tr class="donor">
                        <td>{{ $d['name'] }}</td>
                        <td>{{ $d['is_system'] ? '' : ucfirst($d['standing']) }}</td>
                        <td>{!! $d['is_system'] ? '' : ($d['suggested'] ? ucfirst($d['suggested']) : '<span class="muted">not enough data</span>') !!}</td>
                        <td class="num">{{ $d['donations'] }}</td>
                        <td class="num">{{ number_format($d['units']) }}</td>
                        <td class="num">{{ number_format($d['usable']) }}</td>
                        <td class="num">{{ number_format($d['outdated']) }}</td>
                        <td class="num">{{ number_format($d['trashed']) }}</td>
                        <td class="num">{{ number_format($d['diverted']) }}</td>
                        <td class="num">{{ $pct($d['loss_percent']) }}</td>
                    </tr>
                    @foreach($d['donation_list'] as $n)
                        <tr class="donation">
                            <td class="indent">#{{ $n['id'] }} &middot; {{ $n['date'] }} &middot; {{ $n['status'] }}</td>
                            <td></td><td></td><td></td><td></td>
                            <td class="num">{{ number_format($n['usable']) }}</td>
                            <td class="num">{{ number_format($n['outdated']) }}</td>
                            <td class="num">{{ number_format($n['trashed']) }}</td>
                            <td class="num">{{ number_format($n['diverted']) }}</td>
                            <td class="num">{{ $pct($n['loss_percent']) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="disclaimer">
        Loss % = (outdated + trashed) &divide; everything sorted; diverted goods aren't counted against a donor.
        Suggested standing needs {{ $c['min_donations'] }}+ sorted donations and {{ $c['min_units'] }}+ units: watch at
        {{ $c['watch_loss_percent'] }}%+ loss, decline at {{ $c['decline_loss_percent'] }}%+ over {{ $c['decline_min_donations'] }}+
        donations, preferred at {{ $c['preferred_loss_percent'] }}% or less over {{ $c['preferred_min_donations'] }}+ donations and
        {{ $c['preferred_min_units'] }}+ units. A suggestion is not a decision: the standing shown is the one staff set.
        Quantities are in each item's own unit.
    </div>
</body>
</html>
