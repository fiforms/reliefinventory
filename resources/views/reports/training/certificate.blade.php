{{-- Training certificates (TrainingReportController::certificatesBuilder): one landscape page per trainee who
     completed all six stations. --}}
@php
    $f = $report['filters'];
    $date = $f['from'] === $f['to'] ? \Carbon\Carbon::parse($f['from'])->format('F j, Y')
        : \Carbon\Carbon::parse($f['from'])->format('F j').' – '.\Carbon\Carbon::parse($f['to'])->format('F j, Y');
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
            border: 6px double #1e3a8a; padding: 0.45in 0.6in; height: 6.3in; box-sizing: border-box;
            text-align: center; page-break-after: always;
        }
        .cert:last-child { page-break-after: auto; }
        .org { font-family: Arial, sans-serif; font-size: 14px; letter-spacing: 2px; text-transform: uppercase; color: #1e3a8a; }
        h1 { font-size: 40px; margin: 14px 0 6px 0; color: #1e3a8a; }
        .lead { font-size: 16px; margin: 10px 0; }
        .name { font-size: 34px; font-weight: bold; border-bottom: 1px solid #888; display: inline-block; padding: 0 40px 4px 40px; margin: 8px 0 14px 0; }
        .stations { font-family: Arial, sans-serif; font-size: 13px; margin: 16px auto; }
        .stations span { display: inline-block; margin: 3px 10px; }
        .tick { color: #166534; font-weight: bold; }
        .footer { margin-top: 26px; font-family: Arial, sans-serif; font-size: 12px; color: #444; }
        .sign { display: inline-block; width: 2.6in; border-top: 1px solid #444; margin: 34px 0.4in 0 0.4in; padding-top: 4px; }
    </style>
</head>
<body>
    @forelse ($trainees as $t)
        <div class="cert">
            <div class="org">{{ config('app.name') }}</div>
            <h1>Certificate of Completion</h1>
            <div class="lead">This certifies that</div>
            <div class="name">{{ $t['name'] }}</div>
            <div class="lead">
                completed hands-on warehouse training at all six stations{{ $t['team'] ? ' with Team '.$t['team'] : '' }}
                on {{ $date }}.
            </div>
            <div class="stations">
                @foreach ($t['stations'] as $n => $s)
                    <span><span class="tick">&#10003;</span> {{ $n }}. {{ $s['name'] }}</span>
                @endforeach
            </div>
            <div class="footer">
                <span class="sign">Trainer</span>
                <span class="sign">Date</span>
            </div>
        </div>
    @empty
        <p style="font-family: Arial, sans-serif;">No trainee has completed all six stations in this range yet.</p>
    @endforelse
</body>
</html>
