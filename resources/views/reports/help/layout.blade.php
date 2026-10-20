{{-- Shared shell for every printable Help guide (HelpReportController::builder).
     Each guide's own view only supplies its title and content sections, so
     styling and the header/footer stay identical across guides. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title')</title>
    <style>
        /* Margins live on @page (not body padding) so every page of the guide keeps them. */
        @page { size: letter; margin: 36px 42px; }
        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 4px 0;
        }
        .subtitle {
            color: #666;
            font-size: 12px;
            margin-bottom: 18px;
        }
        .section {
            margin-top: 22px;
        }
        .section h2 {
            font-size: 14px;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 4px;
            margin-bottom: 10px;
            color: #1e3a8a;
        }
        ol, ul {
            margin: 0;
            padding-left: 20px;
        }
        li {
            margin-bottom: 8px;
        }
        .intro {
            background: #f3f4f6;
            border-radius: 6px;
            padding: 10px 14px;
            font-style: italic;
        }
        .footer {
            font-size: 10px;
            color: #888;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <h1>@yield('title')</h1>
    <div class="subtitle">{{ config('app.name') }} &mdash; printed {{ now()->format('F j, Y') }}</div>

@yield('content')

    <div class="footer">
        Questions or something not working the way this guide describes? Use Report an Issue from the
        profile menu in the app.
    </div>
</body>
</html>
