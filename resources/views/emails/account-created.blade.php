<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<p>Hi {{ $person->full_name }},</p>

<p>
    An account has been created for you on {{ config('app.name') }}{{ $createdBy ? ' by '.$createdBy->full_name : '' }}.
</p>

<p>
    <strong>Log in at:</strong> <a href="{{ \App\Models\Control\Warehouse::current()->publicUrl('/login') }}">{{ \App\Models\Control\Warehouse::current()->publicUrl('/login') }}</a><br>
    <strong>Email:</strong> {{ $person->email }}
</p>

<p>
    Your password will be given to you separately by
    {{ $createdBy ? $createdBy->full_name : 'your administrator' }}; it is not included in this email.
    @if ($person->password_change_required)
    You'll be asked to choose your own password the first time you log in.
    @endif
</p>

<p>
    If you never receive your password, use
    <a href="{{ \App\Models\Control\Warehouse::current()->publicUrl('/forgot-password') }}">Forgot your password?</a> on the login page to set one of your own.
</p>
