<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pallet Labels</title>
    <style>
        body {
            text-align: center;
            font-family: Arial, sans-serif;
        }
        .pallet-number {
            font-size: 150px;
            font-weight: bold;
            margin-top: 50px;
        }
        .pallet-info {
            font-size: 20px;
            margin-top: 20px;
        }
        .pallet-contents {
            font-weight: bold;
        }
        .barcode {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 180px;
        }
        .label-page {
            page-break-after: always;
        }
        .label-page:last-child {
            page-break-after: auto;
        }
    </style>
</head>
<body>
@foreach ($labels as $label)
    <div class="label-page">
        @include('reports.partials.pallet_label', ['label' => $label])
    </div>
@endforeach
</body>
</html>
