<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
@php
    $labels = ['seen' => 'Acknowledged', 'in_development' => 'In Development', 'resolved' => 'Resolved'];
    $report = $log->feedbackReport;
@endphp

<p>Hi {{ $report->person->full_name }},</p>

@if ($isTransition)
<p>
    Your {{ $report->type === 'bug' ? 'bug report' : 'feature request' }}
    "{{ \Illuminate\Support\Str::limit($report->message, 80) }}" is now
    <strong>{{ $labels[$log->status] ?? $log->status }}</strong>.
</p>
@else
<p>
    A new comment was added to your {{ $report->type === 'bug' ? 'bug report' : 'feature request' }}
    "{{ \Illuminate\Support\Str::limit($report->message, 80) }}"
    (currently <strong>{{ $labels[$log->status] ?? $log->status }}</strong>):
</p>
@endif

@if ($log->comment)
<p><strong>Note from the developer:</strong></p>
<p>{{ $log->comment }}</p>
@endif

<p>Thanks for helping improve Relief Inventory.</p>
