<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Contact-official message (legacy includes/process/process_contacts.inc.php
 * `action=email`). Sends the visitor's message to a listed competition
 * contact; the body mirrors legacy's "Begin/End Sender's Message" framing
 * with the sender's name/email appended for reply-ability.
 *
 * ponytail: legacy's full anti-spam belt (honeypot, fill-time/mouse/key
 * heuristics, 60s rate-limit file, reCAPTCHA/hCAPTCHA verification, spam
 * regex) is deliberately not ported here — Laravel validation + CSRF +
 * rate-limiting cover the trust boundary for this turn. Add the legacy
 * capture-verification layer if the public form is exposed to real spam.
 */
final class ContactMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $toName,
        private readonly string $toEmail,
        private readonly string $fromName,
        private readonly string $fromEmail,
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly string $contestName,
        private readonly ?string $ccEmail = null,
    ) {
        $this->to($this->toEmail, $this->toName);

        // prefsEmailCC ("Contact Form CC"): also send the visitor a copy of
        // their own message. Blank/absent leaves the message uncopied.
        if ($this->ccEmail !== null && $this->ccEmail !== '') {
            $this->cc($this->ccEmail);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.$this->contestName.'] '.$this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: self::body());
    }

    private function body(): string
    {
        return '<p><small>------------- Begin Sender\'s Message -------------</small></p>'
            .'<p>'.e($this->body).'</p>'
            .'<p><small>-------------- End Sender\'s Message --------------</small></p>'
            .'<p><strong>Sender\'s Contact Info</strong><br>'
            .'Name: '.e($this->fromName).'<br>'
            .'Email: '.e($this->fromEmail).'</p>';
    }
}
