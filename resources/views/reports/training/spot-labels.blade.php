{{-- Training Mode table spot labels (TrainingAdminController::spotLabelsBuilder): one label per spot (A-1 ... C-4), on the
     pallet label stock, to tape on the tables. The barcode is the location code itself, which is what Inventory Movement
     matches a scanned location against. The compact barcode is drawn in whole printer dots (App\Support\LabelBarcode). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Table Spot Labels</title>
    <style>
        @page { size: {{ $paper['width'] }}in {{ $paper['height'] }}in; margin: 0; }
        body { margin: 0; text-align: center; font-family: Arial, sans-serif; }
        .label-page { height: {{ $paper['height'] }}in; overflow: hidden; page-break-after: always; }
        .label-page:last-child { page-break-after: auto; }
        .table-name { font-size: {{ $compact ? 18 : 30 }}px; margin-top: {{ $compact ? 28 : 70 }}px; }
        .spot { font-size: {{ $compact ? 96 : 190 }}px; font-weight: bold; line-height: 1; margin-top: {{ $compact ? 8 : 20 }}px; }
        .barcode { margin-top: {{ $compact ? 24 : 50 }}px; height: {{ $compact ? 90 : 160 }}px; }
        .barcode > div { margin: 0 auto; }
        .code { font-size: {{ $compact ? 13 : 20 }}px; margin-top: 6px; }
    </style>
</head>
<body>
@foreach ($spots as $spot)
    <div class="label-page">
        <div class="table-name">Table {{ explode('-', $spot)[0] }}</div>
        <div class="spot">{{ $spot }}</div>
        <div class="barcode">
            @php
                echo $compact
                    ? App\Support\LabelBarcode::html($spot, 4, 90)
                    : DNS1D::getBarcodeHTML($spot, 'C128', 3, 160);
            @endphp
        </div>
        <div class="code">{{ $spot }}</div>
    </div>
@endforeach
</body>
</html>
