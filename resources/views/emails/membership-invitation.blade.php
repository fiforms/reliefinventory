<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<p>Hello,</p>

<p>
    {{ $invitedBy ?: 'An administrator' }} has invited you to join <strong>{{ $warehouse->display_name }}</strong> on {{ config('app.name') }}
    using your existing login ({{ $email }}).
</p>

<p>
    Nothing changes until you accept. To accept, open the link below and enter your password:<br>
    <a href="{{ $url }}">{{ $url }}</a>
</p>

<p>
    The link works until {{ $expiresAt->toFormattedDateString() }}. If you were not expecting this, ignore this email: your login is not
    attached to {{ $warehouse->display_name }} unless you accept.
</p>
