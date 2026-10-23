{{-- Trainee sign-up poster (TrainingAdminController::signupPosterBuilder): the /join QR code and URL, big enough
     to scan from across a room or to put on a slide. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign Up for Training</title>
    <style>
        @page { size: letter; margin: 0.75in; }
        body { font-family: Arial, sans-serif; color: #1e3a8a; margin: 0; text-align: center; }
        h1 { font-size: 44px; margin: 0.4in 0 0.1in 0; }
        .lead { font-size: 22px; color: #333; margin-bottom: 0.4in; }
        .qr { width: 5in; margin: 0 auto; }
        .qr svg { width: 5in; height: 5in; }
        .url { font-size: 22px; font-family: 'Courier New', monospace; color: #111; margin-top: 0.3in; word-break: break-all; }
        .foot { font-size: 14px; color: #555; margin-top: 0.3in; }
    </style>
</head>
<body>
    <h1>Sign up for training</h1>
    <div class="lead">Scan with your phone camera — it takes less than a minute.</div>
    <div class="qr">{!! $qr !!}</div>
    <div class="url">{{ $url }}</div>
    <div class="foot">{{ config('app.name') }}</div>
</body>
</html>
