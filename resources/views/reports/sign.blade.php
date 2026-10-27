@php
    // One sign, laid out by App\Support\SignLayout (all measurements in pt). The same view is
    // the PDF (WeasyPrint, fonts from disk) and the Signs page's preview ($preview: an HTML
    // page in an iframe, fonts over HTTP), so the preview is the printed layout.
    $fontUrl = fn (string $file) => $preview
        ? url('/setup/signs/fonts/'.$file)
        : 'file://'.resource_path('fonts/liberation/'.$file);
    $pt = fn (float $v) => round($v, 2).'pt';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @font-face { font-family: 'Sign Sans'; font-weight: 700; src: url('{{ $fontUrl('LiberationSans-Bold.ttf') }}'); }
        @font-face { font-family: 'Sign Sans'; font-weight: 400; src: url('{{ $fontUrl('LiberationSans-Regular.ttf') }}'); }
        @page { size: letter {{ $layout['orientation'] }}; margin: 0; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'Sign Sans', 'Liberation Sans', Arial, sans-serif; color: #000; background: #fff; }
        .page { position: relative; width: {{ $pt($layout['page']['width']) }}; height: {{ $pt($layout['page']['height']) }}; overflow: hidden; background: #fff; }
        .border { position: absolute; box-sizing: border-box; }
        .header { position: absolute; text-align: center; font-weight: 700; }
        .arrow { position: absolute; }
        .text { position: absolute; text-align: center; }
        .line { white-space: pre; overflow: visible; }
        .title { font-weight: 700; }
        .subtitle { font-weight: 400; }
        @if ($preview)
        html, body { width: {{ $pt($layout['page']['width']) }}; height: {{ $pt($layout['page']['height']) }}; overflow: hidden; }
        @endif
        @media print { .header { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="page">
    <div class="border" style="left: {{ $pt($layout['border']['inset']) }}; top: {{ $pt($layout['border']['inset']) }};
        width: {{ $pt($layout['page']['width'] - 2 * $layout['border']['inset']) }}; height: {{ $pt($layout['page']['height'] - 2 * $layout['border']['inset']) }};
        border: {{ $pt($layout['border']['width']) }} solid {{ $layout['border']['color'] }};"></div>

    @if ($header = $layout['header'])
        <div class="header" style="left: {{ $pt($header['x']) }}; top: {{ $pt($header['y']) }}; width: {{ $pt($header['width']) }}; height: {{ $pt($header['height']) }};
            line-height: {{ $pt($header['height']) }}; font-size: {{ $pt($header['size']) }}; background: {{ $header['background'] }}; color: {{ $header['color'] }};">{{ $header['label'] }}</div>
    @endif

    @foreach ($layout['arrows'] as $arrow)
        <svg class="arrow" style="left: {{ $pt($arrow['x']) }}; top: {{ $pt($arrow['y']) }};" width="{{ $pt($arrow['size']) }}" height="{{ $pt($arrow['size']) }}" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <polygon points="6,36 56,36 56,10 96,50 56,90 56,64 6,64" fill="#000" transform="rotate({{ $arrow['rotate'] }} 50 50)" />
        </svg>
    @endforeach

    <div class="text" style="left: {{ $pt($layout['box']['x']) }}; top: {{ $pt($layout['box']['y'] + $layout['padTop']) }}; width: {{ $pt($layout['box']['width']) }};">
        @foreach ($layout['title']['lines'] as $line)
            <div class="line title" style="font-size: {{ $pt($layout['title']['size']) }}; line-height: {{ $pt($layout['title']['lineHeight']) }};">{{ $line }}</div>
        @endforeach
        @if ($sub = $layout['subtitle'])
            <div style="height: {{ $pt($sub['gap']) }};"></div>
            @foreach ($sub['lines'] as $line)
                <div class="line subtitle" style="font-size: {{ $pt($sub['size']) }}; line-height: {{ $pt($sub['lineHeight']) }};">{{ $line }}</div>
            @endforeach
        @endif
    </div>
</div>
</body>
</html>
