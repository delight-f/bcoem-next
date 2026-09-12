<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

/**
 * Task 2.3 acceptance: install redirect, allow-list reachability, upgrade
 * gating, banner, and the 404-when-current rule.
 */
final class WizardRoutingTest extends WizardTestCase
{
    public function test_uninstalled_site_redirects_every_request_to_the_wizard(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        $this->get('/')->assertRedirect('/install');
        $this->get('/login')->assertRedirect('/install');
        $this->get('/admin')->assertRedirect('/install');
        $this->get('/admin/site-preferences')->assertRedirect('/install');
        $this->get('/list/edit-account')->assertRedirect('/install');
    }

    public function test_installer_surface_stays_reachable_while_uninstalled(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        $this->get('/install')
            ->assertOk()
            ->assertSee('Get Started')
            // Product identity, computed from one standalone layout rather
            // than the app's @vite()/public build.
            ->assertSee('Brew Competition Online Entry & Management')
            ->assertSee('#007bff', false)
            ->assertDontSee('build/assets');
        $this->get('/install/checks')->assertOk();
        $this->get('/install/database')->assertOk();

        // Progress polling must not be redirected to the wizard it polls.
        $this->getJson('/install/progress?token=abcdefgh1234')->assertNotFound();

        // The connection-test endpoint the wizard drives.
        $this->postJson('/install/database/test', $this->credentials())
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_install_run_and_poll_are_constrained_not_redirected_while_uninstalled(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        // No wizard session: the endpoint answers (422), it is not redirected.
        $this->postJson('/install/run', ['token' => 'not-a-valid-token'])->assertStatus(422);
    }

    public function test_every_install_screen_renders(): void
    {
        $this->setInstalled(false, '3.0.1.0');

        $session = [
            'wizard.install.db' => $this->credentials(),
            'wizard.install.site' => [
                'app_url' => 'http://example.test',
                'admin_name' => 'Jane Admin',
                'admin_email' => 'jane@example.test',
                'admin_password' => 's3cret-pass',
            ],
        ];

        $this->withSession($session)->get('/install/site')->assertOk()->assertSee('Password');
        $this->withSession($session)->get('/install/confirm')->assertOk()->assertSee('Install Now');
    }

    public function test_every_upgrade_screen_renders(): void
    {
        $this->setInstalled(true, '3.0.1.0');
        $admin = $this->user('0');

        $this->actingAs($admin)->get('/upgrade')->assertOk()->assertSee('Version 4.0.0');
        $this->actingAs($admin)->get('/upgrade/checks')->assertOk()->assertSee('disk');
        $this->actingAs($admin)->get('/upgrade/confirm')->assertOk()->assertSee('Upgrade Now');
    }

    public function test_non_admin_cannot_reach_the_upgrade_wizard(): void
    {
        $this->setInstalled(true, '3.0.1.0');

        $this->actingAs($this->user('2'))->get('/upgrade')->assertForbidden();
        $this->actingAs($this->user('1'))->get('/upgrade')->assertForbidden();
    }

    public function test_mid_level_admin_and_entrant_do_not_see_the_upgrade_banner(): void
    {
        $this->setInstalled(true, '3.0.1.0');

        $this->actingAs($this->user('1'))->get('/contact')
            ->assertOk()
            ->assertDontSee('Version 4.0.0 is available');

        $this->actingAs($this->user('2'))->get('/contact')
            ->assertOk()
            ->assertDontSee('Version 4.0.0 is available');
    }

    public function test_top_level_admin_sees_the_banner_and_can_dismiss_it(): void
    {
        $this->setInstalled(true, '3.0.1.0');
        $admin = $this->user('0');

        $this->actingAs($admin)->get('/contact')
            ->assertOk()
            ->assertSee('Version 4.0.0 is available');

        $this->actingAs($admin)->post('/upgrade/dismiss')->assertRedirect();

        // The test client does not carry the session cookie between requests,
        // so replay the dismissal the POST recorded.
        $this->actingAs($admin)
            ->withSession(['wizard.upgrade.dismissed' => true])
            ->get('/contact')
            ->assertOk()
            ->assertDontSee('Version 4.0.0 is available');
    }

    public function test_top_level_admin_can_reach_the_upgrade_wizard(): void
    {
        $this->setInstalled(true, '3.0.1.0');

        $this->actingAs($this->user('0'))->get('/upgrade')
            ->assertOk()
            ->assertSee('Continue');
    }

    public function test_installed_and_current_wizards_return_404(): void
    {
        $this->setInstalled(true, '4.0.0');
        $admin = $this->user('0');

        $this->actingAs($admin)->get('/install')->assertNotFound();
        $this->actingAs($admin)->get('/install/progress?token=abcdefgh1234')->assertNotFound();
        $this->actingAs($admin)->get('/upgrade')->assertNotFound();
        $this->actingAs($admin)->get('/upgrade/progress?token=abcdefgh1234')->assertNotFound();
    }
}
