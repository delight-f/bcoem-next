<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Laravel\Dusk\Browser;

/**
 * Marker assertions for the Bootstrap-5 migration (issue 2 + the migration
 * batches 3-12). These are the gate every migrated page must pass: rendered
 * DOM carries Bootstrap 5 markers and no legacy Bootstrap-3 / daisyUI-only
 * class or attribute markers.
 *
 * The marker sets are the authoritative spec from the migration issues. A
 * "no BS3 / no daisy" assertion deliberately FAILS on any page that still
 * carries a legacy marker — that is how a migration batch proves a page is
 * done (its page test flips from red to green once the markers are gone).
 *
 * BS3-vs-BS5 naming collision guard: several class names are valid in BOTH
 * Bootstrap versions (.btn, .form-control, .form-group, .nav, .nav-pills,
 * .modal, .container-fluid, .hidden-print). The BS3-only list below is the
 * set that Bootstrap 5 does NOT provide and that only appears when legacy
 * BS3 markup was left un-ported.
 */
trait AssertsBs5Markers
{
    /**
     * Bootstrap-3-only class names (absent from Bootstrap 5). Any of these in
     * the DOM means an admin/backoffice blade still carries un-ported BS3
     * markup.
     *
     * @var string[]
     */
    protected array $bs3OnlyClasses = [
        'panel', 'panel-default', 'panel-info', 'panel-success',
        'panel-danger', 'panel-warning', 'panel-heading', 'panel-body',
        'panel-title', 'navbar-inverse', 'btn-default', 'btn-xs',
        'caret', 'input-group-addon', 'navmenu', 'navmenu-inverse',
        'navmenu-fixed-right', 'hidden-xs', 'hidden-sm', 'pull-right',
        'help-block', 'form-horizontal', 'page-header',
    ];

    /**
     * daisyUI-only class names / attributes. Bootstrap does not emit these.
     *
     * @var string[]
     */
    protected array $daisyOnlyMarkers = [
        'input-bordered', 'select-bordered', 'textarea-bordered',
        'modal-box', 'modal-action', 'table-zebra', 'btn-ghost',
        'btn-square', 'label-text', 'data-theme',
    ];

    /**
     * Bootstrap-5-only markers that must be present on a migrated page.
     *
     * @var string[]
     */
    protected array $bs5Markers = [
        'data-bs-toggle', 'data-bs-target', 'data-bs-dismiss',
        'btn-close', 'form-label', 'form-select', 'container-xxl',
    ];

    /**
     * Assert the rendered page carries none of the Bootstrap-3-only markers.
     */
    protected function assertBs3Absent(Browser $browser, string $where = 'page'): void
    {
        $found = self::markersInHtml($browser->driver->getPageSource(), $this->bs3OnlyClasses);
        $this->assertSame(
            [],
            $found,
            "Bootstrap-3-only markers found on {$where}: ".implode(', ', $found)
        );
    }

    /**
     * Assert the rendered page carries none of the daisyUI-only markers.
     */
    protected function assertDaisyAbsent(Browser $browser, string $where = 'page'): void
    {
        $found = self::markersInHtml($browser->driver->getPageSource(), $this->daisyOnlyMarkers);
        $this->assertSame(
            [],
            $found,
            "daisyUI-only markers found on {$where}: ".implode(', ', $found)
        );
    }

    /**
     * Assert at least one Bootstrap-5 marker renders (proves a real BS5
     * stylesheet/markup, not a stub).
     */
    protected function assertBs5Present(Browser $browser, string $where = 'page'): void
    {
        $found = self::markersInHtml($browser->driver->getPageSource(), $this->bs5Markers);
        $this->assertNotEmpty(
            $found,
            "No Bootstrap-5 markers present on {$where} — page is not BS5."
        );
    }

    /**
     * The full migration gate for a page: no BS3, no daisy, BS5 present.
     * Used by migration batches once a page is fully ported.
     */
    protected function assertPageIsBootstrap5(Browser $browser, string $where = 'page'): void
    {
        $this->assertBs3Absent($browser, $where);
        $this->assertDaisyAbsent($browser, $where);
        $this->assertBs5Present($browser, $where);
    }

    /**
     * Whether a single marker token (class name or data-* attribute) appears
     * in the given HTML. data-* markers match as an attribute (`name=`);
     * class markers match at word boundaries so `panel` does not match
     * `panelization` but does match `panel` as its own class.
     */
    public static function markerInHtml(string $html, string $marker): bool
    {
        if (str_starts_with($marker, 'data-')) {
            return str_contains($html, $marker.'=');
        }

        return (bool) preg_match('/\b'.preg_quote($marker, '/').'\b/', $html);
    }

    /**
     * Pure marker detector over an HTML string — browser-free so the gate
     * logic is unit-testable without a Dusk run. Returns the subset of
     * $markers present in $html.
     *
     * @param  string[]  $markers
     * @return string[]
     */
    public static function markersInHtml(string $html, array $markers): array
    {
        return array_values(array_filter(
            $markers,
            fn (string $m): bool => self::markerInHtml($html, $m)
        ));
    }
}
