{{-- Shared page + label CSS for pallet.blade.php and pallet-batch.blade.php.
     $paper: ['width' => in, 'height' => in] of the label media; $compact: the narrow-label layout
     (2.312 x 4 in), which scales the big pallet number and barcode down to fit. @page must match the
     paper size set in PalletReportController::labelPaper, with a zero margin (WeasyPrint stacks its own). --}}
@page { size: {{ $paper['width'] }}in {{ $paper['height'] }}in; margin: 0; }
body {
    margin: 0;
    text-align: center;
    font-family: Arial, sans-serif;
}
.pallet-number {
    font-size: {{ $compact ? 88 : 150 }}px;
    font-weight: bold;
    margin-top: {{ $compact ? 24 : 50 }}px;
}
.pallet-info {
    font-size: {{ $compact ? 14 : 20 }}px;
    margin-top: {{ $compact ? 8 : 20 }}px;
}
.pallet-contents {
    font-weight: bold;
}
.pallet-location {
    font-weight: bold;
    font-size: {{ $compact ? 20 : 34 }}px;
}
.barcode {
    margin-top: {{ $compact ? 10 : 20 }}px;
    height: {{ $compact ? 110 : 200 }}px;
}
.barcode > div {
    margin: 0 auto;
}
