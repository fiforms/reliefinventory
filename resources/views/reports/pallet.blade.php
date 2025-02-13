<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pallet Report</title>
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
        .barcode {
            margin-top: 20px;
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 180px;
        }
    </style>
</head>
<body>

	<div class="pallet-number">
	  {{ $pallet_shortnum }}
	</div>
    <!-- Barcode Section -->
    <div class="barcode">
@php
		 echo DNS1D::getBarcodeHTML($pallet_id_str, 'C128', 3, 200);
@endphp
    </div>
        <p class="pallet-info">{{ $pallet_id_str}}</p>
        <div class="pallet-info">Created: {{ $date_created }}</div>
        
    </div>
</body>
</html>
