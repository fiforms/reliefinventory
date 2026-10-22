{{-- One inventory label. The barcode is in whole printer dots (InventoryLabelController::barcode): $bc['px_per_dot'] px
     per dot, bars drawn from an origin that is itself a whole number of dots. Compact: the barcode is rotated -90° into
     a strip down the left edge (reading bottom to top), its number printed beside it; otherwise it sits upright at the
     bottom. --}}
@php
    $bc = $label['barcode'];
    $dot = $bc['px_per_dot'];
    $lengthPx = round($bc['length_dots'] * $dot, 3);
    $quietPx = round(10 * $bc['module_dots'] * $dot, 3);
    $barHeightPx = $bc['bar_height_px'];
@endphp
<div class="text">
    <div class="item-number">{{ $label['item_number'] }}</div>
    <div class="description">{{ $label['description'] }}</div>
    <div class="qty">Qty {{ number_format($label['qty']) }}</div>
    <div class="meta">Exp: {{ $label['expires_on'] ?? 'none' }}</div>
    @if ($label['pallet'])
        <div class="meta">Pallet {{ $label['pallet'] }}</div>
    @endif
</div>
@if ($compact)
    {{-- Origin (left, top) on a whole dot; rotate(-90deg) about it maps the box onto x in [0, height], y in [0, length]. --}}
    @php
        $left = round(16 * $dot, 4);
        $top = round((16 + $bc['length_dots']) * $dot, 4);
    @endphp
    <div style="position:absolute; left:{{ $left }}px; top:{{ $top }}px; width:{{ $lengthPx }}px; height:{{ $barHeightPx + 14 }}px; transform-origin:0 0; transform:rotate(-90deg);">
        <div style="position:absolute; left:{{ $quietPx }}px; top:0;">{!! $label['barcode']['html'] !!}</div>
        <div style="position:absolute; left:0; top:{{ $barHeightPx + 2 }}px; width:{{ $lengthPx }}px; text-align:center; font-size:11px; font-family:monospace; white-space:nowrap;">{{ $label['code'] }}</div>
    </div>
@else
    <div style="position:absolute; left:{{ round(16 * $dot, 4) }}px; right:0; bottom:40px;">
        <div style="margin:0 auto; width:{{ $lengthPx }}px; position:relative; height:{{ $barHeightPx }}px;">
            <div style="position:absolute; left:{{ $quietPx }}px; top:0;">{!! $label['barcode']['html'] !!}</div>
        </div>
        <div style="text-align:center; font-size:20px; font-family:monospace; margin-top:6px;">{{ $label['code'] }}</div>
    </div>
@endif
