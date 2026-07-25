<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 6-digit new-device sign-in code (PLAN D1). Not queued — see TestMessage;
 * the login request blocks on this send, which is why the transport timeout is
 * pinned low.
 */
class LoginVerificationCode extends Mailable
{
    public function __construct(
        public string $code,
        public string $username,
        public ?string $ip = null,
        public int $minutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->code.' is your '.config('app.name').' sign-in code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-code');
    }
}
