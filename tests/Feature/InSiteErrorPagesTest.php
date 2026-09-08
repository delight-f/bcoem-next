<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;

/**
 * In-site error pages (P3 Slice 5, PARITY-022). Legacy renders HTTP
 * errors as numeric public sections (index.pub.php:113-121) with the
 * contest chrome + "<strong>{code} Error.</strong> {text}" salutation;
 * the port's 404 view must render in the public layout, not Laravel's
 * bare page.
 */
final class InSiteErrorPagesTest extends PublicSurfaceTestCase
{
    use InteractsWithViews;

    public function test_unknown_path_renders_in_site_404(): void
    {
        $response = $this->get('/definitely-not-a-page');
        $response->assertNotFound();

        $html = $response->getContent();
        self::assertIsString($html);
        $this->assertStringContainsString('404 Error.', $html);
        $this->assertStringContainsString('Page not found.', $html);
        // Contest chrome (public layout) is present — not a bare error page.
        $this->assertStringContainsString('Brew Competition Online Entry', $html);
    }

    public function test_404_view_renders_salutation_without_errors_var(): void
    {
        // Regression: the public layout's login modal reads $errors; error
        // views do not share Laravel's shared $errors binding, so the view
        // must guard it (previously a 500 on any unknown path).
        $view = view('errors.404', ['status' => 404])->render();
        $this->assertStringContainsString('404 Error.', $view);
    }
}
