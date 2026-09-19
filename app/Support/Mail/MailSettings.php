<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Throwable;

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
 * Unset (NULL) or 'default' transport leaves `.env` in charge, so an
 * existing install that never opened the email tab keeps its current
 * behaviour — and "Application default (from .env)" is a real, sticky
 * choice rather than a blank that a saved host overrode.
 */
final class MailSettings
{
    /** Last-resort mail program path, and the shape most hosts expect. */
    public const SENDMAIL_FALLBACK = '/usr/sbin/sendmail -t -i';

    /**
     * Stored when the admin picks "Application default (from .env)". An
     * explicit sentinel so the choice survives a saved SMTP host, which the
     * legacy fallback below would otherwise turn into the smtp transport.
     */
    public const DEFAULT_TRANSPORT = 'default';

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

        if ($stored === self::DEFAULT_TRANSPORT) {
            return null;
        }

        if (in_array($stored, self::TRANSPORTS, true)) {
            return $stored;
        }

        return trim((string) $ctx->prefsStr('prefsEmailHost')) !== '' ? 'smtp' : null;
    }

    /**
     * Decrypt a stored credential. A value that does not decrypt is legacy
     * plaintext (written before the encrypt-at-rest migration) or a rotated
     * APP_KEY, so it is used as-is rather than silently emptying the field.
     */
    private static function secret(?string $stored): string
    {
        $value = (string) $stored;

        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
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
            $transport = (string) config('mail.default');
        }

        if ($transport === 'sendmail') {
            // Naming a mail program this server does not have delivers
            // nothing — that is the "exit code 127: /usr/sbin/sendmail: not
            // found" state. Say so, rather than let the admin page promise a
            // delivery the host cannot make.
            return self::sendmailBinaryExists((string) config('mail.mailers.sendmail.path', ''));
        }

        return in_array($transport, self::DELIVERING, true);
    }

    public static function label(string $transport): string
    {
        return self::LABELS[$transport] ?? $transport;
    }

    /**
     * Command the "sendmail" transport runs, i.e. the host's own mail
     * program.
     *
     * php.ini's sendmail_path is the program PHP's mail() itself runs, so it
     * is by definition present and permitted on this server — shared hosts
     * that block outbound SMTP (NearlyFreeSpeech.NET, whose FAQ sends PHP at
     * mail() and everything else at /usr/bin/sendmail) only work through it.
     * A MAIL_SENDMAIL_PATH carried over from a different host is the
     * "Process failed with exit code 127: sh: /usr/sbin/sendmail: not found"
     * failure, so a configured path counts only when it exists here.
     *
     * @param  string|null  $configured  MAIL_SENDMAIL_PATH, as read by config.
     */
    public static function sendmailPath(?string $configured = null): string
    {
        $configured = trim((string) $configured);
        $native = trim((string) ini_get('sendmail_path'));

        $command = match (true) {
            $configured !== '' && self::sendmailBinaryExists($configured) => $configured,
            $native !== '' => $native,
            $configured !== '' => $configured,
            default => self::SENDMAIL_FALLBACK,
        };

        // Symfony's SendmailTransport rejects a command carrying neither -t
        // (take recipients from the headers) nor -bs (SMTP over stdio).
        if (! str_contains($command, ' -t') && ! str_contains($command, ' -bs')) {
            $command .= ' -t -i';
        }

        return $command;
    }

    private static function sendmailBinaryExists(string $command): bool
    {
        $binary = trim(explode(' ', $command)[0], "'\"");

        return $binary !== '' && @is_file($binary);
    }

    private static function configure(string $transport, TenantContext $ctx): void
    {
        $from = trim((string) $ctx->prefsStr('prefsEmailFrom'));

        if ($from !== '') {
            config(['mail.from.address' => $from]);
        }

        // sendmail needs no config of its own: its path is server-specific
        // (see sendmailPath(), which config/mail.php already resolved), so
        // selecting the mailer is the whole change.
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
        $password = self::secret($ctx->prefsStr('prefsEmailPassword'));

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
        $key = trim(self::secret($ctx->prefsStr('prefsEmailApiKey')));

        if ($key !== '') {
            config(['services.'.$provider.'.key' => $key]);
        }
    }
}
