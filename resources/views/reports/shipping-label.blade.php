{{-- Shipping label, 4 x 6.5 in ($paper). Facts match the BOL. Zero @page margin (WeasyPrint stacks its own);
     long text is clipped by fixed-height boxes so it can never push the label onto a second page. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $orderNumber }}</title>
    <style>
        @page { size: {{ $paper['width'] }}in {{ $paper['height'] }}in; margin: 0; }
        body { margin: 0; padding: 0.15in; font-family: Arial, sans-serif; color: #000; font-size: 12px; }
        .label-box { height: {{ $paper['height'] - 0.3 }}in; overflow: hidden; }
        .order-number { font-size: 30px; font-weight: bold; text-align: center; }
        .barcode { text-align: center; height: 64px; margin: 4px 0 8px; overflow: hidden; }
        .barcode > div { margin: 0 auto; }
        .field { border-top: 1px solid #000; padding: 3px 0; overflow: hidden; }
        .k { font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #444; }
        .v { font-size: 13px; }
        .shipto .v { font-size: 22px; font-weight: bold; }
        .shipto { height: 1.6in; }
        .shipto .addr { font-size: 18px; font-weight: normal; margin-top: 2px; }
        .contact { height: 0.55in; }
        .contact .v { font-size: 15px; }
        .row { display: flex; }
        .row .field { flex: 1; height: 0.5in; }
        .row .v { font-size: 15px; }
        .row .field + .field { margin-left: 8px; }
        .instr { height: 1.25in; }
        .instr .v { font-size: 14px; }
        .row .pallets { font-size: 30px; font-weight: bold; }
    </style>
</head>
<body>
<div class="label-box">
    <div class="order-number">{{ $orderNumber }}</div>
    <div class="barcode">{!! DNS1D::getBarcodeHTML($orderNumber, 'C128', 2, 60) !!}</div>

    <div class="field shipto">
        <div class="k">Ship To / Delivery Address</div>
        <div class="v">{{ \Illuminate\Support\Str::limit($order->person?->full_name ?? 'Unknown', 40) }}</div>
        @if($order->person?->address || $order->person?->city)
            <div class="v addr">
                {{ $order->person->address }}<br>
                {{ trim($order->person->city.', '.$order->person->state.' '.$order->person->zip, ', ') }}
            </div>
        @endif
    </div>
    <div class="field contact">
        <div class="k">Contact</div>
        <div class="v">{{ \Illuminate\Support\Str::limit(trim(($order->contact_name ?? '').($order->contact_phone ? ' — '.$order->contact_phone : '')), 60) }}</div>
    </div>
    <div class="row">
        <div class="field"><div class="k">Fulfillment Method</div><div class="v">{{ ucfirst($order->fulfillment_method ?? '') }}</div></div>
        <div class="field"><div class="k">Pallets</div><div class="v pallets">{{ $order->pallet_count ?? '' }}</div></div>
    </div>
    <div class="row">
        <div class="field"><div class="k">Date Packed</div><div class="v">{{ $datePacked?->format('M j, Y') }}</div></div>
        <div class="field"><div class="k">Requested Date</div><div class="v">{{ $order->needed_by_date ? \Illuminate\Support\Carbon::parse($order->needed_by_date)->format('M j, Y') : '' }}</div></div>
    </div>
    <div class="field instr">
        <div class="k">Special Instructions</div>
        <div class="v">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) $order->special_instructions), 200) ?: '—' }}</div>
    </div>
</div>
</body>
</html>
