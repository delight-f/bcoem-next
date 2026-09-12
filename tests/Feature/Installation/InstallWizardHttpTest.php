<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Support\Wizard\ProgressTracker;
use Illuminate\Support\Facades\Crypt;

/**
 * Drives the wizard's own endpoints over HTTP while the site is uninstalled:
 * the double-submit guard, the encrypted-at-rest admin password, and the
 * pollable failure marker with both messages.
 */
final class InstallWizardHttpTest extends WizardTestCase
{
    /**
     * @param  array<string, string>  $db
     * @return array<string, array<string, string>>
     */
    private function wizardSession(array $db): array
    {
        return [
            'wizard.install.db' => $db,
            'wizard.install.site' => [
                'app_url' => 'http://example.test',
                'admin_name' => 'Jane Admin',
                'admin_email' => 'jane@example.test',
                'admin_password' => Crypt::encryptString('s3cret-pass'),
            ],
        ];
    }

    public function test_site_screen_stores_the_admin_password_encrypted(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        $this->withSession(['wizard.install.db' => $this->credentials()])
            ->post('/install/site', [
                'app_url' => 'http://example.test',
                'admin_name' => 'Jane Admin',
                'admin_email' => 'jane@example.test',
                'admin_password' => 's3cret-pass',
                'admin_password_confirmation' => 's3cret-pass',
            ])
            ->assertRedirect(route('wizard.install.confirm'));

        $stored = session('wizard.install.site.admin_password');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('s3cret-pass', $stored, 'the raw session value must not be the plaintext');
        $this->assertSame('s3cret-pass', Crypt::decryptString($stored));
    }

    public function test_run_refuses_a_session_password_that_is_not_ciphertext(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        $session = $this->wizardSession($this->credentials());
        $session['wizard.install.site']['admin_password'] = 'plain-text';

        $this->withSession($session)
            ->postJson('/install/run', ['token' => 'abc123def456abc203'])
            ->assertStatus(422);
    }

    public function test_failed_install_is_pollable_with_plain_and_technical_messages(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        $db = $this->credentials();
        $db['password'] = 'definitely-not-the-password';
        $token = 'abc123def456abc201';

        $this->withSession($this->wizardSession($db))
            ->postJson('/install/run', ['token' => $token])
            ->assertOk();

        $marker = (array) $this->getJson('/install/progress?token='.$token)->assertOk()->json();
        $error = is_array($marker['error'] ?? null) ? $marker['error'] : [];

        $this->assertSame('failed', $marker['status'] ?? null);
        $this->assertStringContainsString('password', strtolower((string) ($error['plain'] ?? '')));
        $this->assertNotSame('', (string) ($error['technical'] ?? ''));
        $this->assertNotSame($error['plain'] ?? '', $error['technical'] ?? '');

        $this->assertNull(session('wizard.install.site'), 'the failure path must clear the stored password');
    }

    public function test_double_submit_guard_refuses_a_second_run(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        $tracker = new ProgressTracker;
        $tracker->acquire('install');

        try {
            $this->withSession($this->wizardSession($this->credentials()))
                ->postJson('/install/run', ['token' => 'abc123def456abc202'])
                ->assertStatus(409);
        } finally {
            $tracker->release('install');
        }
    }
}
