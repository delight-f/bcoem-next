<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Support\Tenant\TenantContext;

/**
 * Applies the site-preferences email settings to the runtime mailer.
 *
 * The legacy admin saved SMTP credentials and rebuilt the PHPMailer
 * transport on every send. The port's settings page kept collecting those
 * fields, but nothing read them — mail went out through whatever `.env`
 * configured, and the page reported success regardless. This class is the
 * missing link: it maps the saved row onto Laravel's mail config so the
 * admin page selects the transport for real.
 *
 * Transports:
 *   smtp      — a mail server the admin has credentials for.
 *   sendmail  — the host's own mail program (the same local MTA that backs
 *               PHP's mail()). This is the option for shared hosts that
 *               block outbound SMTP, e.g. cPanel/nfshost.
 *   resend    — HTTPS API (port 443), so an SMTP block is irrelevant.
 *   postmark  — HTTPS API, same reasoning.
 *   log       — write messages to the log, deliver nothing (diagnostics).
 *
 * Unset (NULL) transport leaves `.env` in charge, so an existing install
 * that never opened the email tab keeps its current behaviour.
 */
final class MailSettings
{
    /** Selectable transports, in display order. */
    public const TRANSPORTS = ['smtp', 'sendmail', 'resend', 'postmark', 'log'];

    /** Transports that hand a message to a real delivery agent. */
    public const DELIVERING = ['smtp', 'sendmail', 'resend', 'postmark'];

    /** Human labels for the admin select. */
    public const LABELS = [
        'smtp' => 'SMTP server',
        'sendmail' => "This server's own mail program (sendmail)",
        'resend' => 'Resend (HTTPS API)',
        'postmark' => 'Postmark (HTTPS API)',
        'log' => 'Log only — do not deliver',
    ];

    /**
     * Point the mailer at the configured transport.
     *
     * Called once per web request by ApplyMailSettings; a no-op when the
     * admin has not chosen a transport.
     */
    public static function apply(?TenantContext $ctx = null): void
    {
        $ctx ??= TenantContext::load();

        if (self::disabled($ctx)) {
            // "Allow BCOE&M to Send Emails" = No. Route everything to the
            // log so the promise holds without touching each send site.
            config(['mail.default' => 'log']);

            return;
        }

        $transport = self::transport($ctx);

        if ($transport === null) {
            return;
        }

        self::configure($transport, $ctx);
    }

    /** Email sending is switched off in preferences. */
    public static function disabled(TenantContext $ctx): bool
    {
        // Only an explicit "No" disables sending. The legacy column also
        // holds other values (the parity dump ships 3), which historically
        // meant sending was on — treating those as "off" would silently
        // blackhole mail on a freshly imported site.
        return (string) $ctx->prefsStr('prefsEmailSMTP') === '0';
    }

    /**
     * The effective transport, or null when the admin has not chosen one.
     * An install that predates the transport column but has an SMTP host
     * saved is treated as SMTP so its settings start working.
     */
    public static function transport(TenantContext $ctx): ?string
    {
        $stored = strtolower(trim((string) $ctx->prefsStr('prefsEmailTransport')));

        if (in_array($stored, self::TRANSPORTS, true)) {
            return $stored;
        }

        return trim((string) $ctx->prefsStr('prefsEmailHost')) !== '' ? 'smtp' : null;
    }

    /**
     * Whether the current settings would actually deliver a message, as
     * opposed to writing it to the log. Drives the honest wording on the
     * send-test-email page.
     */
    public static function delivers(TenantContext $ctx): bool
    {
        if (self::disabled($ctx)) {
            return false;
        }

        $transport = self::transport($ctx);

        if ($transport === null) {
            // Nothing chosen: whatever .env says decides.
            return in_array((string) config('mail.default'), self::DELIVERING, true);
        }

        return in_array($transport, self::DELIVERING, true);
    }

    public static function label(string $transport): string
    {
        return self::LABELS[$transport] ?? $transport;
    }

    private static function configure(string $transport, TenantContext $ctx): void
    {
        $from = trim((string) $ctx->prefsStr('prefsEmailFrom'));

        if ($from !== '') {
            config(['mail.from.address' => $from]);
        }

        // sendmail needs no config of its own: its path is server-specific
        // and stays under env/config control (cPanel typically wants
        // "-t -i"), so selecting the mailer is the whole change.
        match ($transport) {
            'smtp' => self::configureSmtp($ctx),
            'resend' => self::configureApiKey('resend', $ctx),
            'postmark' => self::configureApiKey('postmark', $ctx),
            default => null,
        };

        config(['mail.default' => $transport]);
    }

    private static function configureSmtp(TenantContext $ctx): void
    {
        $encrypt = strtolower(trim((string) $ctx->prefsStr('prefsEmailEncrypt')));
        $port = (int) (string) $ctx->prefsStr('prefsEmailPort');
        $username = trim((string) $ctx->prefsStr('prefsEmailUsername'));
        $password = (string) $ctx->prefsStr('prefsEmailPassword');

        // Symfony picks the scheme itself only for port 465; an explicit
        // "ssl" selection must force implicit TLS (smtps), while tls/none
        // stay plain smtp and let STARTTLS negotiate.
        $scheme = $encrypt === 'ssl' ? 'smtps' : 'smtp';

        config(['mail.mailers.smtp' => array_merge(
            (array) config('mail.mailers.smtp', []),
            [
                'transport' => 'smtp',
                'scheme' => $scheme,
                'host' => trim((string) $ctx->prefsStr('prefsEmailHost')),
                'port' => $port > 0 ? $port : 587,
                'username' => $username === '' ? null : $username,
                'password' => $password === '' ? null : $password,
            ],
        )]);
    }

    private static function configureApiKey(string $provider, TenantContext $ctx): void
    {
        $key = trim((string) $ctx->prefsStr('prefsEmailApiKey'));

        if ($key !== '') {
            config(['services.'.$provider.'.key' => $key]);
        }
    }
}
