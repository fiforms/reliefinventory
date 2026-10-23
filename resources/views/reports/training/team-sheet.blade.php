{{-- Training Mode team activity sheets (TrainingAdminController::teamSheetsBuilder): one per team, in that team's
     rotation order, Round 1 (segment 1) and Round 2 (segment 2) for each station. Content: App\Support\TrainingGuide.
     $sheets = [TrainingGuide::sheetFor(n), ...]; $drivers = team => [phone, pin] from the last Prepare Training. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Training Team Sheets</title>
    <style>
        @page { size: letter; margin: 0.5in 0.55in; }
        body { font-family: Arial, sans-serif; color: #222; margin: 0; font-size: 11.5px; line-height: 1.4; }
        .sheet { page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        h1 { font-size: 22px; margin: 0; }
        .subtitle { color: #666; font-size: 11px; margin: 2px 0 10px 0; }
        .box { border: 1px solid #bbb; border-radius: 4px; padding: 6px 10px; margin-bottom: 10px; background: #f8fafc; }
        table.rotation { border-collapse: collapse; width: 100%; margin-bottom: 10px; }
        table.rotation th, table.rotation td { border: 1px solid #bbb; padding: 3px 6px; text-align: left; }
        table.rotation th { background: #eef2ff; }
        .station { border-top: 2px solid #1e3a8a; padding-top: 4px; margin-top: 8px; page-break-inside: avoid; }
        .station h2 { font-size: 13px; color: #1e3a8a; margin: 0 0 2px 0; }
        .round { margin: 2px 0 4px 0; }
        .round strong { display: block; }
        ol { margin: 2px 0 0 0; padding-left: 18px; }
        li { margin-bottom: 1px; }
        .check { float: right; font-size: 11px; color: #444; }
    </style>
</head>
<body>
@foreach ($sheets as $sheet)
    <div class="sheet">
        <h1>Team {{ $sheet['team'] }} — Activity Sheet</h1>
        <div class="subtitle">{{ config('app.name') }} hands-on training · two segments of {{ \App\Support\TrainingGuide::SEGMENT_MINUTES }} minutes · about 8 minutes per station</div>

        <div class="box">
            <strong>Logging in:</strong> on a station's tablet, tap your name, choose Team {{ $sheet['team'] }}, and tap Start.
            Not on the list? Tap Add New. Tap "Staff login" in the yellow strip when you're done, or just hand the tablet to the next team.
            <strong>Move on</strong> when the trainer calls time: go to the next station on the list below (Station 6 goes back to Station 1).
            <br><strong>Segment 1</strong> = Round 1 at every station. <strong>Segment 2</strong> = Round 2 at every station, in the same order.
            @if (! empty($drivers[$sheet['team']]))
                <br><strong>Your team driver (Station 6, Driver Portal):</strong> phone {{ $drivers[$sheet['team']]['phone'] }}, PIN {{ $drivers[$sheet['team']]['pin'] }}
            @endif
        </div>

        <table class="rotation">
            <thead><tr><th>Slot</th><th>Minutes</th><th>Station</th><th>Round 1 (segment 1)</th><th>Round 2 (segment 2)</th></tr></thead>
            <tbody>
                @foreach ($sheet['slots'] as $slot)
                    <tr>
                        <td>{{ $slot['slot'] }}</td>
                        <td>{{ $slot['start'] }}–{{ $slot['end'] }}</td>
                        <td>{{ $slot['station'] }}. {{ $slot['name'] }}</td>
                        <td>{{ $slot['activity']['round1']['title'] }}</td>
                        <td>{{ $slot['activity']['round2']['title'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @foreach ($sheet['slots'] as $slot)
            <div class="station">
                <h2>Slot {{ $slot['slot'] }} · Station {{ $slot['station'] }}: {{ $slot['name'] }} <span style="font-weight:normal; color:#555;">({{ $slot['activity']['page'] }})</span></h2>
                @foreach (['round1' => 'Round 1', 'round2' => 'Round 2'] as $key => $label)
                    <div class="round">
                        <span class="check">&#9744; done</span>
                        <strong>{{ $label }}: {{ $slot['activity'][$key]['title'] }}</strong>
                        <ol>
                            @foreach ($slot['activity'][$key]['steps'] as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
