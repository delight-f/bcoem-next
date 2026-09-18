<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\MailSettings;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The site-preferences email transport must actually drive the mailer.
 *
 * Before this existed the admin page collected SMTP credentials that
 * nothing read: mail went out through `.env` regardless, and the test-email
 * page reported success even when the message only reached the log.
 */
final class MailSettingsTest extends PublicSurfaceTestCase
{
    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->origPrefs = (array) DB::table('preferences')->where('id', 1)->first();
    }

    protected function tearDown(): void
    {
        DB::table('preferences')->where('id', 1)->update($this->origPrefs);

        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function prefs(array $values): void
    {
        DB::table('preferences')->where('id', 1)->update($values);
    }

    private function apply(): void
    {
        MailSettings::apply(TenantContext::load());
    }

    public function test_sendmail_transport_is_selected(): void
    {
        // The option for hosts that block outbound SMTP: hand the message
        // to the local mail program instead.
        $this->prefs(['prefsEmailSMTP' => '1', 'prefsEmailTransport' => 'sendmail']);

        $this->apply();

        self::assertSame('sendmail', config('mail.default'));
        self::assertSame($this->sendmailBinaryExists(), MailSettings::delivers(TenantContext::load()));
    }

    /** Whether the configured mail program is actually on this machine. */
    private function sendmailBinaryExists(): bool
    {
        $binary = trim(explode(' ', (string) config('mail.mailers.sendmail.path'))[0], "'\"");

        return is_file($binary);
    }

    public function test_sendmail_path_ignores_a_program_this_server_does_not_have(): void
    {
        // The live failure: .env carried /usr/sbin/sendmail from an older
        // host, and the transport died with "Process failed with exit code
        // 127: sh: /usr/sbin/sendmail: not found" instead of handing the
        // message to the program PHP's mail() runs.
        $path = MailSettings::sendmailPath('/nonexistent-bcoem-host/sendmail -t -i');

        // A host where php.ini names no program at all has nothing better to
        // fall back on, so the configured path survives there.
        if (trim((string) ini_get('sendmail_path')) !== '') {
            self::assertStringNotContainsString('/nonexistent-bcoem-host', $path);
        }

        self::assertMatchesRegularExpression('/ (?:-t|-bs)/', $path);
    }

    public function test_sendmail_path_keeps_a_configured_program_that_exists(): void
    {
        // An admin who knows the right path keeps it.
        $command = PHP_BINARY.' -t -i';

        self::assertSame($command, MailSettings::sendmailPath($command));
    }

    public function test_sendmail_path_always_carries_a_mode_flag(): void
    {
        // Symfony's SendmailTransport throws on a command with neither -t
        // nor -bs, so a bare path has to be completed rather than passed on.
        self::assertSame(PHP_BINARY.' -t -i', MailSettings::sendmailPath(PHP_BINARY));
        self::assertStringContainsString(' -t', MailSettings::sendmailPath(''));
    }

    public function test_https_provider_transport_uses_stored_api_key(): void
    {
        $this->prefs([
            'prefsEmailSMTP' => '1',
            'prefsEmailTransport' => 'resend',
            'prefsEmailApiKey' => 're_test_key',
        ]);

        $this->apply();

        self::assertSame('resend', config('mail.default'));
        self::assertSame('re_test_key', config('services.resend.key'));
        self::assertTrue(MailSettings::delivers(TenantContext::load()));
    }

    public function test_smtp_transport_maps_host_port_and_encryption(): void
    {
        $this->prefs([
            'prefsEmailSMTP' => '1',
            'prefsEmailTransport' => 'smtp',
            'prefsEmailHost' => 'smtp.example.test',
            'prefsEmailPort' => '465',
            'prefsEmailEncrypt' => 'ssl',
            'prefsEmailUsername' => 'mailer@example.test',
            'prefsEmailPassword' => 'secret',
        ]);

        $this->apply();

        self::assertSame('smtp', config('mail.default'));
        self::assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        self::assertSame(465, config('mail.mailers.smtp.port'));
        // Explicit "ssl" must force implicit TLS; Symfony only guesses that
        // from port 465 on its own.
        self::assertSame('smtps', config('mail.mailers.smtp.scheme'));
        self::assertSame('mailer@example.test', config('mail.mailers.smtp.username'));
    }

    public function test_tls_encryption_stays_plain_smtp_for_starttls(): void
    {
        $this->prefs([
            'prefsEmailSMTP' => '1',
            'prefsEmailTransport' => 'smtp',
            'prefsEmailHost' => 'smtp.example.test',
            'prefsEmailPort' => '587',
            'prefsEmailEncrypt' => 'tls',
        ]);

        $this->apply();

        self::assertSame('smtp', config('mail.mailers.smtp.scheme'));
    }

    public function test_from_address_is_applied(): void
    {
        $this->prefs([
            'prefsEmailSMTP' => '1',
            'prefsEmailTransport' => 'sendmail',
            'prefsEmailFrom' => 'entries@example.test',
        ]);

        $this->apply();

        self::assertSame('entries@example.test', config('mail.from.address'));
    }

    public function test_sending_switched_off_routes_everything_to_the_log(): void
    {
        // "Allow BCOE&M to Send Emails" = No must actually stop delivery,
        // without every send site having to check the preference.
        $this->prefs(['prefsEmailSMTP' => '0', 'prefsEmailTransport' => 'smtp']);

        $this->apply();

        self::assertSame('log', config('mail.default'));
        self::assertTrue(MailSettings::disabled(TenantContext::load()));
        self::assertFalse(MailSettings::delivers(TenantContext::load()));
    }

    public function test_legacy_smtp_host_infers_smtp_when_no_transport_stored(): void
    {
        // An install that predates the transport column but has an SMTP host
        // saved should start honouring it rather than fall back to .env.
        $this->prefs([
            'prefsEmailSMTP' => '1',
            'prefsEmailTransport' => null,
            'prefsEmailHost' => 'smtp.legacy.test',
        ]);

        $this->apply();

        self::assertSame('smtp', config('mail.default'));
        self::assertSame('smtp.legacy.test', config('mail.mailers.smtp.host'));
    }

    public function test_unset_transport_leaves_env_mailer_in_charge(): void
    {
        $this->prefs([
            'prefsEmailSMTP' => '1',
            'prefsEmailTransport' => null,
            'prefsEmailHost' => null,
        ]);

        $before = config('mail.default');

        $this->apply();

        self::assertSame($before, config('mail.default'));
        self::assertNull(MailSettings::transport(TenantContext::load()));
    }

    public function test_legacy_non_zero_smtp_value_does_not_disable_sending(): void
    {
        // The parity dump ships prefsEmailSMTP=3. Only an explicit "0" means
        // off; treating 3 as off would silently blackhole mail on import.
        $this->prefs([
            'prefsEmailSMTP' => '3',
            'prefsEmailTransport' => 'resend',
            'prefsEmailApiKey' => 're_test_key',
        ]);

        self::assertFalse(MailSettings::disabled(TenantContext::load()));
        self::assertTrue(MailSettings::delivers(TenantContext::load()));
    }
}
