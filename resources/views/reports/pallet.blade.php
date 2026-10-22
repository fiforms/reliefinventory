<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pallet Report</title>
    <style>
        @include('reports.partials.pallet_label_css', ['paper' => $paper, 'compact' => $compact])
    </style>
</head>
<body>
    @include('reports.partials.pallet_label', ['label' => $label, 'compact' => $compact])
</body>
</html>
