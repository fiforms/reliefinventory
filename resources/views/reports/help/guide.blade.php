{{-- One printable Help guide (HelpReportController::builder). $guide is an App\Support\HelpGuide: the same
     Markdown-rendered intro and sections the screen page shows (Pages/Help/Guide.vue). --}}
@extends('reports.help.layout')

@section('title', $title)

@section('content')
    @if ($guide->intro !== '')
        <p class="intro">{!! $guide->intro !!}</p>
    @endif

    @foreach ($guide->sections as $html)
        <div class="section">
            {!! $html !!}
        </div>
    @endforeach
@endsection
