@php
    // Avery 8160 / 5160: Letter, 3 columns x 10 rows of 2-5/8 x 1 in labels,
    // 1/2 in top margin, 3/16 in side margin, 1/8 in between columns.
    $top = 0.5;
    $left = 0.1875;
    $pitchX = 2.75;
    $pitchY = 1.0;
    // Largest font (pt) that fits every line across and down a label: about 171pt of
    // usable width at ~0.52 em per character, 65pt of height at 1.15 line spacing.
    $fontSize = function (array $lines) {
        $longest = max(array_map('mb_strlen', $lines));
        $size = min(10, 171 / max(1, $longest * 0.52), 65 / (count($lines) * 1.15));

        return max(6.5, floor($size * 2) / 2);
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mailing Labels</title>
    <style>
        @page { size: letter; margin: 0; }
        body { font-family: Arial, sans-serif; color: #000; margin: 0; }
        .sheet { position: relative; width: 8.5in; height: 11in; page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .label { position: absolute; width: 2.625in; height: 1in; overflow: hidden; }
        .text { padding: 0 0.125in; line-height: 1.15; }
        .line { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 2.375in; }
        .empty { padding: 1in; font-size: 14pt; }
    </style>
</head>
<body>
@if ($count === 0)
    <div class="empty">No mailing labels: nobody selected has a complete address.</div>
@endif
@foreach ($sheets as $sheet)
    @if ($count > 0)
    <div class="sheet">
        @foreach ($sheet as $i => $lines)
            @if ($lines)
            <div class="label" style="top: {{ $top + intdiv($i, 3) * $pitchY }}in; left: {{ $left + ($i % 3) * $pitchX }}in;">
                @php($size = $fontSize($lines))
                {{-- Centered down the label by padding: 72pt tall, each line 1.15 x the font size. --}}
                <div class="text" style="font-size: {{ $size }}pt; padding-top: {{ max(0, (72 - count($lines) * $size * 1.15) / 2) }}pt;">
                    @foreach ($lines as $line)
                        <div class="line">{{ $line }}</div>
                    @endforeach
                </div>
            </div>
            @endif
        @endforeach
    </div>
    @endif
@endforeach
</body>
</html>
