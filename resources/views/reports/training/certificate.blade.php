{{-- Training certificates (TrainingReportController::certificatesBuilder): one landscape page per trainee who
     completed all six stations. The event title and location come from Setup > Training Mode. --}}
@php
    $f = $report['filters'];
    $date = $f['from'] === $f['to'] ? \Carbon\Carbon::parse($f['from'])->format('F j, Y')
        : \Carbon\Carbon::parse($f['from'])->format('F j').' – '.\Carbon\Carbon::parse($f['to'])->format('F j, Y');
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('icons/icon-192.png')));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Training Certificates</title>
    <style>
        @page { size: letter landscape; margin: 0.5in; }
        body { font-family: Georgia, 'Times New Roman', serif; color: #222; margin: 0; }
        .cert {
            border: 6px double #1e3a8a; padding: 0.5in 0.8in; height: 7.45in; box-sizing: border-box;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            text-align: center; page-break-after: always;
        }
        .cert:last-child { page-break-after: auto; }
        .logo { width: 0.9in; height: 0.9in; margin-bottom: 14px; }
        .org { font-family: Arial, sans-serif; font-size: 14px; letter-spacing: 2px; text-transform: uppercase; color: #1e3a8a; }
        h1 { font-size: 44px; margin: 20px 0 40px 0; color: #1e3a8a; }
        .lead { font-size: 18px; margin: 0; }
        .name { font-size: 38px; font-weight: bold; border-bottom: 1px solid #888; padding: 0 48px 6px 48px; margin: 18px 0 34px 0; }
        .event { font-size: 24px; font-style: italic; margin: 14px 0 20px 0; }
        .sign { width: 3in; border-top: 1px solid #444; margin-top: 80px; padding-top: 4px; font-family: Arial, sans-serif; font-size: 12px; color: #444; }
    </style>
</head>
<body>
    @forelse ($trainees as $t)
        <div class="cert">
            <img class="logo" src="{{ $logo }}" alt="">
            <div class="org">{{ config('app.name') }}</div>
            <h1>Certificate of Completion</h1>
            <div class="lead">This certifies that</div>
            <div class="name">{{ $t['name'] }}</div>
            <div class="lead">has completed</div>
            <div class="event">{{ $eventTitle ?: 'Hands-on Warehouse Training' }}</div>
            <div class="lead">{{ $eventLocation ? 'held at '.$eventLocation.' on ' : 'on ' }}{{ $date }}</div>
            <div class="sign">Trainer</div>
        </div>
    @empty
        <p style="font-family: Arial, sans-serif;">No trainee has completed all six stations in this range yet.</p>
    @endforelse
</body>
</html>
