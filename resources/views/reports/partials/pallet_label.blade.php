{{-- One pallet label (4x6.5in page). Shared by the single-pallet and
     whole-donation batch reports. --}}
<div class="pallet-number">
  {{ $label['pallet_shortnum'] }}
</div>
<div class="barcode">
@php
    echo DNS1D::getBarcodeHTML($label['pallet_id_str'], 'C128', 3, 200);
@endphp
</div>
<p class="pallet-info">{{ $label['pallet_id_str'] }}</p>
<div class="pallet-info">{{ $label['pallet_kind_label'] }}</div>
@if (!empty($label['contents']))
    <div class="pallet-info pallet-contents">{{ $label['contents'] }}</div>
@endif
<div class="pallet-info">Created: {{ $label['date_created'] }}</div>
