@component('mail.layout')
    <p style="margin:0 0 12px;font-size:17px;font-weight:600;">Reset your password</p>

    <p style="margin:0 0 12px;">Someone asked to reset the password for
        <strong style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $user->username }}</strong>
        on {{ config('app.name') }}. Use the link below to choose a new one:</p>

    <p style="margin:20px 0;">
        <a href="{{ $resetUrl }}"
           style="display:inline-block;padding:11px 20px;background:#f26c23;color:#ffffff;text-decoration:none;border-radius:5px;font-weight:600;">
            Choose a new password
        </a>
    </p>

    <p style="margin:0 0 12px;font-size:13px;color:#7b8794;">
        The link expires in {{ $ttlMinutes }} minutes and can be used once.
        @if ($requestedIp)
            The request came from {{ $requestedIp }}.
        @endif
    </p>

    <p style="margin:0 0 12px;font-size:13px;color:#7b8794;">
        If the button does not work, paste this into your browser:<br>
        <span style="word-break:break-all;">{{ $resetUrl }}</span>
    </p>

    <p style="margin:0;font-size:13px;color:#7b8794;">
        If you did not ask for this, ignore this message — your password has not changed.
    </p>
@endcomponent
