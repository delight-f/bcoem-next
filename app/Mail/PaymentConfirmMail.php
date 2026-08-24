<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Payment confirmation (P3.6). Port of the entrant mail in legacy
 * `ppv.php`, provider-neutralized per ledger/payments.md: "payment
 * received" wording, no PayPal mention (decision D7). Sent to the entrant
 * on every verified paid event — gateway or manual marking alike, since
 * both route through PaymentService::markPaid().
 */
final class PaymentConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstName,
        public readonly string $contestName,
        /** @var list<int> */
        public readonly array $entries,
        public readonly string $amount,
        public readonly string $currency,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->contestName.': Payment Received',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.payment-confirm',
        );
    }
}
