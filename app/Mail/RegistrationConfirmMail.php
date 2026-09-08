<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Registration confirmation (P3.6). Port of the confirm branch in
 * `process_users_register.inc.php` (register_text_037..043): subject
 * "«comp»: Registration Confirmation", body greets the registrant and
 * echoes back what they entered. Sent only when prefsEmailRegConfirm == 1
 * (legacy also required SMTP mode; the port's transport is env-configured).
 *
 * Legacy emailed the plaintext security answer; the port deliberately
 * omits it — a password-equivalent secret does not belong in an inbox.
 */
final class RegistrationConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstName,
        public readonly string $contestName,
        /** @var list<array{label: string, value: string}> */
        public readonly array $rows = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->contestName.': Registration Confirmation',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.registration-confirm',
        );
    }
}
