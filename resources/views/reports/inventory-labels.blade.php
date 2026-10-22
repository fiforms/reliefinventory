<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Labels</title>
    <style>
        {{-- $paper: label media in inches; $compact: the narrow-label layout (barcode rotated along the long edge).
             Page size/margins as in the pallet labels (WeasyPrint stacks its own margin on a body padding). --}}
        @page { size: {{ $paper['width'] }}in {{ $paper['height'] }}in; margin: 0; }
        body { margin: 0; font-family: Arial, sans-serif; color: #000; }
        .label-page { position: relative; width: {{ $paper['width'] }}in; height: {{ $paper['height'] }}in; overflow: hidden; page-break-after: always; }
        .label-page:last-child { page-break-after: auto; }
        .item-number { font-weight: bold; }
        .description { font-weight: bold; }
        .qty { font-weight: bold; }
        .meta { color: #000; }
        .code-text { text-align: center; white-space: nowrap; }
        .code-text, .bars { position: absolute; }
        @if ($compact)
        .text { position: absolute; left: {{ round(0.74 * 96, 2) }}px; right: 6px; top: 12px; }
        .item-number { font-size: 28px; }
        .description { font-size: 15px; margin-top: 6px; max-height: 5.2em; overflow: hidden; line-height: 1.15; }
        .qty { font-size: 22px; margin-top: 12px; }
        .meta { font-size: 13px; margin-top: 8px; }
        @else
        .text { position: absolute; left: 24px; right: 24px; top: 36px; text-align: center; }
        .item-number { font-size: 96px; }
        .description { font-size: 30px; margin-top: 14px; max-height: 3.5em; overflow: hidden; line-height: 1.15; }
        .qty { font-size: 48px; margin-top: 28px; }
        .meta { font-size: 24px; margin-top: 14px; }
        @endif
    </style>
</head>
<body>
@foreach ($labels as $label)
    <div class="label-page">
        @include('reports.partials.inventory_label', ['label' => $label, 'compact' => $compact, 'paper' => $paper])
    </div>
@endforeach
</body>
</html>
