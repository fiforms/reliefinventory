<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pallet Labels</title>
    <style>
        @include('reports.partials.pallet_label_css', ['paper' => $paper, 'compact' => $compact])
        .label-page {
            height: {{ $paper['height'] }}in;
            overflow: hidden;
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
        @include('reports.partials.pallet_label', ['label' => $label, 'compact' => $compact])
    </div>
@endforeach
</body>
</html>
