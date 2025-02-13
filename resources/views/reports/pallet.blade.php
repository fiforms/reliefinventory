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
        .report-title {
            font-size: 50px;
            font-weight: bold;
            margin-top: 50px;
        }
        .pallet-info {
            font-size: 40px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="report-title">Pallet Report</div>
    <div class="pallet-info">Pallet ID: {{ $pallet_id }}</div>
    <div class="pallet-info">Date Created: {{ $date_created }}</div>
</body>
</html>
