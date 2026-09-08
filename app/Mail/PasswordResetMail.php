<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset email (P3.1c). Port of the legacy reset message from
 * `account_checks.ajax.php`: subject "«comp»: Password Reset Request",
 * body greets the registrant, names the competition, and links the reset
 * URL.
 */
final class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $contestName,
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->contestName.': Password Reset Request',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset',
        );
    }
}
