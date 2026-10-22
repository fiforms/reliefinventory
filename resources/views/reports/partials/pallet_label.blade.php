{{-- One pallet label (4 x 6.5 in, or the $compact 2.312 x 4 in layout). Shared by the single-pallet and
     whole-donation batch reports. The compact barcode's module is 1.8923 CSS px = exactly 4 printer dots
     at 203 dpi (so every bar is a whole number of dots on a SATO WS408), 101 modules ≈ 2 in. --}}
<div class="pallet-number">
  {{ $label['pallet_shortnum'] }}
</div>
<div class="barcode">
@php
    echo DNS1D::getBarcodeHTML($label['pallet_id_str'], 'C128', $compact ? 1.8923 : 3, $compact ? 110 : 200);
@endphp
</div>
<p class="pallet-info">{{ $label['pallet_id_str'] }}</p>
<div class="pallet-info">{{ $label['pallet_kind_label'] }}</div>
@if (!empty($label['contents']))
    <div class="pallet-info pallet-contents">{{ $label['contents'] }}</div>
@endif
<div class="pallet-info">Created: {{ $label['date_created'] }}</div>
