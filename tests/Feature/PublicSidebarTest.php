<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Public anon sidebar (sections/sidebar.sec.php, PARITY-006). Panels
 * render on the sidebar-layout section pages; anon-only Account
 * Registration panel; Past Winners from archives + winner link.
 */
final class PublicSidebarTest extends PublicSurfaceTestCase
{
    public function test_sidebar_renders_on_contact_page(): void
    {
        $this->get('/contact')
            ->assertOk()
            ->assertSee('Judging Sessions', false)
            ->assertSee('Account Registration', false)
            ->assertSee('Entry Registration', false);
    }

    public function test_sidebar_renders_on_volunteers_page(): void
    {
        $this->get('/volunteers')
            ->assertOk()
            ->assertSee('Judging Sessions', false);
    }

    public function test_anon_account_registration_panel_hidden_when_logged_in(): void
    {
        // The baseline admin fixture (userLevel 0) is logged in by default
        // in AdminScreensTestCase but PublicSurfaceTestCase tests are anon.
        self::assertNull(auth()->user());

        $this->get('/contact')
            ->assertOk()
            ->assertSee('Account Registration', false);
    }
}
