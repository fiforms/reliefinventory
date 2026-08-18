<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Request Form</title>
    <style>
        @page {
            size: letter;
            margin: 0.75in 0.5in 0.5in 0.5in;
            @top-left {
                content: "Order Request Form — generated {{ $generatedAt->format('F j, Y') }}";
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
            font-size: 22px;
            margin: 0 0 2px 0;
        }
        .subtitle {
            color: #666;
            font-size: 12px;
            margin-bottom: 16px;
        }
        .instructions {
            font-size: 12px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 18px;
        }
        .fields {
            display: flex;
            flex-wrap: wrap;
            gap: 0 28px;
            margin-bottom: 20px;
        }
        .field {
            flex: 1 1 220px;
            margin-bottom: 10px;
        }
        .field label {
            display: block;
            font-size: 10.5px;
            color: #666;
            margin-bottom: 2px;
        }
        .field .line {
            border-bottom: 1px solid #999;
            height: 18px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 18px;
        }
        thead {
            display: table-header-group;
        }
        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        th.category-heading {
            font-size: 13px;
            color: #1e3a8a;
            border-bottom: 2px solid #1e3a8a;
            padding: 0 6px 4px 6px;
            font-weight: bold;
        }
        th, td {
            text-align: left;
            padding: 4px 6px;
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
        td.qty {
            border: 1px solid #bbb;
            border-radius: 3px;
            height: 20px;
        }
        .other-needs {
            margin-top: 24px;
        }
        .other-needs h2 {
            font-size: 13px;
            color: #1e3a8a;
            margin-bottom: 4px;
        }
        .other-needs p {
            font-size: 11px;
            color: #666;
            margin: 0 0 8px 0;
        }
        .other-line {
            border-bottom: 1px solid #999;
            height: 22px;
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
    <h1>Order Request Form</h1>
    <div class="subtitle">
        {{ config('app.name') }} &mdash; generated {{ $generatedAt->format('F j, Y') }}
    </div>

    <div class="instructions">
        Write the quantity needed next to each item below, then return this form (email, fax, or in person) to be
        entered as your order. Items listed here have stock currently available. If you need something not listed,
        use the "Other Needs" section at the bottom.
    </div>

    <div class="fields">
        <div class="field"><label>Organization / POD Name</label><div class="line"></div></div>
        <div class="field"><label>Contact Name</label><div class="line"></div></div>
        <div class="field"><label>Phone</label><div class="line"></div></div>
        <div class="field"><label>Email</label><div class="line"></div></div>
        <div class="field"><label>Delivery Address</label><div class="line"></div></div>
        <div class="field"><label>Date Needed By</label><div class="line"></div></div>
    </div>

    @forelse($categories as $category => $items)
        <table>
            <thead>
                <tr>
                    <th class="category-heading" colspan="4">{{ $category ?? 'Uncategorized' }}</th>
                </tr>
                <tr>
                    <th>Item #</th>
                    <th>Name</th>
                    <th>Unit</th>
                    <th class="qty">Qty Needed</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item['display_number'] ?? '' }}</td>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ $item['unit'] }}</td>
                        <td class="qty"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>No items are currently available to request.</p>
    @endforelse

    <div class="other-needs">
        <h2>Other Needs</h2>
        <p>Anything you need that isn't listed above:</p>
        <div class="other-line"></div>
        <div class="other-line"></div>
        <div class="other-line"></div>
    </div>

    <div class="disclaimer">
        This list reflects items with stock on hand at the time this form was generated and may change before your
        order is processed. Quantities are not guaranteed until confirmed.
    </div>
</body>
</html>
