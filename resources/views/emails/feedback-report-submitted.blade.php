<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
@if ($report->urgent)
<p style="color: #b91c1c;"><strong>URGENT</strong></p>
@endif

<p>
    <strong>{{ $report->type === 'bug' ? 'Bug report' : 'Feature request' }}</strong>
    from {{ $report->person->full_name }} ({{ $report->person->email }})
</p>

<p><strong>Page:</strong> {{ $report->page_title ?: $report->page_url }} ({{ $report->page_url }})</p>

<p><strong>Message:</strong></p>
<p>{{ $report->message }}</p>

@if ($report->user_agent)
<p><strong>Browser:</strong> {{ $report->user_agent }}</p>
@endif

@if ($report->commit_hash)
<p><strong>Running commit:</strong> {{ $report->commit_hash }}</p>
@endif

<p><a href="{{ config('app.url') }}/setup/feedback">View in Relief Inventory</a></p>
