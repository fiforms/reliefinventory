<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pallet Report</title>
    <style>
        @page { size: 4in 6.5in; margin: 0; }
        body {
            margin: 0;
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
            height: 200px;
        }
        .barcode > div {
            margin: 0 auto;
        }
    </style>
</head>
<body>
    @include('reports.partials.pallet_label', ['label' => $label])
</body>
</html>
