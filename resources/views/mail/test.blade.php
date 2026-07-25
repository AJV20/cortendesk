@component('mail.layout')
    <p style="margin:0 0 12px;font-size:17px;font-weight:600;">Your mail settings work.</p>
    <p style="margin:0 0 12px;">This is a test message from the {{ config('app.name') }} console. If you are reading
        it, the SMTP relay accepted the message and delivered it.</p>
    <p style="margin:0;color:#7b8794;font-size:13px;">Nothing else to do — you can close this.</p>
@endcomponent
