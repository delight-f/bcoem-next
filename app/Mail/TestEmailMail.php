<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SMTP settings test message (admin/send_test_email.admin.php): sent from
 * the configured competition identity to the requesting admin's own
 * address, echoing back the active email settings.
 */
final class TestEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{transport: string, delivers: bool, from: string, host: string, username: string, encryption: string, port: string}  $settings
     */
    public function __construct(
        private readonly string $contestName,
        private readonly array $settings,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Testing Email Settings',
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: self::body());
    }

    private function body(): string
    {
        return '<p>A request to send a test email to this address was made from the '
            .e($this->contestName.' Server').' using the following settings:</p>'
            .'<ul>'
            .'<li><strong>Transport:</strong> '.e($this->settings['transport']).'</li>'
            .'<li><strong>Originating Email Address:</strong> '.e($this->settings['from']).'</li>'
            .'<li><strong>Host:</strong> '.e($this->settings['host']).'</li>'
            .'<li><strong>Username:</strong> '.e($this->settings['username']).'</li>'
            .'<li><strong>Encryption:</strong> '.e($this->settings['encryption']).'</li>'
            .'<li><strong>Port:</strong> '.e($this->settings['port']).'</li>'
            .'</ul>'
            .'<p>If you\'re reading this, your settings are correct and emails are being generated successfully.</p>';
    }
}
