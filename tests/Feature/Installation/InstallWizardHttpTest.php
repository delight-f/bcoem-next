<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Support\Wizard\ProgressTracker;

/**
 * Drives the wizard's own endpoints over HTTP while the site is uninstalled:
 * the double-submit guard and the pollable failure marker with both messages.
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
                'admin_password' => 's3cret-pass',
            ],
        ];
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
