<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
<p>
    <strong>New submission for {{ $submission->form->name }}</strong>
    from {{ $submission->submitterDisplayName() }}
    @if ($submission->submitter_email)
        ({{ $submission->submitter_email }})
    @endif
</p>

<table cellpadding="4" cellspacing="0">
    @foreach ($submission->answers as $answer)
        <tr>
            <td valign="top"><strong>{{ $answer->question_label_snapshot }}</strong></td>
            <td>{{ $answer->displayValue() }}</td>
        </tr>
    @endforeach
</table>

<p><a href="{{ config('app.url') }}/setup/forms/{{ $submission->form_id }}/submissions">View in Relief Inventory</a></p>
