<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #53: the .hide-loader overlay hangs forever when the clicked link
 * cannot navigate (mailto:/tel:/sms:, an in-page "#" placeholder, or a
 * new-tab target), and it stayed up through a back/forward-cache restore.
 * The guard is front-end code and this repo has no JS test runner, so pin the
 * source contract here rather than let the regression come back silently.
 */
final class LoaderOverlayGuardTest extends TestCase
{
    private static function appJs(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/app.js');
    }

    public function test_the_loader_is_raised_only_for_links_that_can_navigate(): void
    {
        $js = self::appJs();

        self::assertStringContainsString('const navigatesAway = (el) =>', $js);
        self::assertStringContainsString("if (el.getAttribute('target') === '_blank') return false;", $js);
        self::assertStringContainsString("if (href === '' || href === '#') return false;", $js);
        self::assertStringContainsString('return !/^(mailto|tel|sms|javascript):/i.test(href);', $js);
        self::assertStringContainsString('if (!navigatesAway(el)) return;', $js);
    }

    public function test_the_loader_is_cleared_when_the_page_is_restored_from_cache(): void
    {
        self::assertStringContainsString(
            "window.addEventListener('pageshow', () => loaderEl.classList.remove('show'));",
            self::appJs(),
        );
    }
}
