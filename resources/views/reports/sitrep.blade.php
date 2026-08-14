<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Situation Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 0;
            padding: 36px 42px;
        }
        h1 {
            font-size: 24px;
            margin: 0 0 2px 0;
        }
        .subtitle {
            color: #666;
            font-size: 13px;
            margin-bottom: 4px;
        }
        .disclaimer {
            font-size: 11px;
            color: #888;
            font-style: italic;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            margin-top: 24px;
        }
        .section {
            margin-top: 26px;
        }
        .section h2 {
            font-size: 15px;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 4px;
            margin-bottom: 12px;
            color: #1e3a8a;
        }
        .stat_grid {
            display: flex;
            gap: 18px;
        }
        .stat_block {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .stat_block h3 {
            font-size: 13px;
            margin: 0 0 8px 0;
        }
        .stat_row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            padding: 3px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .stat_row strong {
            font-size: 14px;
        }
        .trend {
            font-size: 11px;
            font-weight: bold;
            margin-top: 6px;
        }
        .trend_up { color: #15803d; }
        .trend_down { color: #b91c1c; }
        .trend_static { color: #666; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        th, td {
            text-align: left;
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
        }
        th {
            color: #666;
            font-weight: normal;
            border-bottom: 2px solid #ccc;
        }
        td.num {
            text-align: right;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>Situation Report</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; generated {{ $data['generated_at']->format('F j, Y \a\t g:i A T') }}
    </div>

    <div class="section">
        <h2>Movement Summary</h2>
        <div class="stat_grid">
            <div class="stat_block">
                <h3>Orders Fulfilled</h3>
                <div class="stat_row"><span>Today</span><strong>{{ $data['orders_fulfilled']['today'] }}</strong></div>
                <div class="stat_row"><span>Last 7 days</span><strong>{{ $data['orders_fulfilled']['last_7_days'] }}</strong></div>
                <div class="stat_row"><span>Last 30 days</span><strong>{{ $data['orders_fulfilled']['last_30_days'] }}</strong></div>
                <div class="stat_row"><span>All time</span><strong>{{ $data['orders_fulfilled']['all_time'] }}</strong></div>
                <div class="trend trend_{{ $data['orders_trend']['direction'] }}">
                    Trend: {{ ucfirst($data['orders_trend']['direction']) }}
                    @if($data['orders_trend']['percent'] !== null) ({{ $data['orders_trend']['percent'] }}%) @endif
                    vs. prior 7 days
                </div>
            </div>
            <div class="stat_block">
                <h3>Donations Completed</h3>
                <div class="stat_row"><span>Today</span><strong>{{ $data['donations_completed']['today'] }}</strong></div>
                <div class="stat_row"><span>Last 7 days</span><strong>{{ $data['donations_completed']['last_7_days'] }}</strong></div>
                <div class="stat_row"><span>Last 30 days</span><strong>{{ $data['donations_completed']['last_30_days'] }}</strong></div>
                <div class="stat_row"><span>All time</span><strong>{{ $data['donations_completed']['all_time'] }}</strong></div>
                <div class="trend trend_{{ $data['donations_trend']['direction'] }}">
                    Trend: {{ ucfirst($data['donations_trend']['direction']) }}
                    @if($data['donations_trend']['percent'] !== null) ({{ $data['donations_trend']['percent'] }}%) @endif
                    vs. prior 7 days
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Current Pipeline</h2>
        <div class="stat_grid">
            <table>
                <thead><tr><th>Donations</th><th class="num">Count</th></tr></thead>
                <tbody>
                    @foreach($data['pipeline']['donations'] as $stage => $count)
                        <tr><td>{{ $stage }}</td><td class="num">{{ $count }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <table>
                <thead><tr><th>Orders</th><th class="num">Count</th></tr></thead>
                <tbody>
                    @foreach($data['pipeline']['orders'] as $stage => $count)
                        <tr><td>{{ $stage }}</td><td class="num">{{ $count }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2>Orders by County</h2>
        @if(count($data['county_breakdown']))
            <table>
                <thead><tr><th>County</th><th class="num">Orders</th></tr></thead>
                <tbody>
                    @foreach($data['county_breakdown'] as $row)
                        <tr><td>{{ $row['county'] }}</td><td class="num">{{ $row['count'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>No orders placed yet.</p>
        @endif
    </div>

    <div class="section">
        <h2>Stock on Hand</h2>
        <div class="stat_row"><span>Item types stocked</span><strong>{{ $data['inventory_summary']['item_types_with_stock'] }}</strong></div>
        <div class="stat_row"><span>Total units on hand</span><strong>{{ $data['inventory_summary']['total_units_on_hand'] }}</strong></div>
        @if(count($data['inventory_summary']['top_categories']))
            <table style="margin-top: 8px;">
                <thead><tr><th>Top Categories</th><th class="num">Units</th></tr></thead>
                <tbody>
                    @foreach($data['inventory_summary']['top_categories'] as $cat)
                        <tr><td>{{ $cat['category'] }}</td><td class="num">{{ $cat['units'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="disclaimer">
        This is a live operational snapshot generated at the moment of viewing, not an official or final report.
        Figures reflect activity currently recorded in the system and may be revised as data entry catches up with
        physical operations.
    </div>
</body>
</html>
