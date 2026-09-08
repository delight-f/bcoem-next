<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Concerns\AssertsBs5Markers;
use Tests\TestCase;

/**
 * Unit coverage for the BS5-migration marker vocabulary (issue 2). Exercises
 * the pure, browser-free detector so the migration gate logic is validated
 * without a Dusk run. The trait's browser-facing methods (assertBs3Absent /
 * assertDaisyAbsent / assertBs5Present / assertPageIsBootstrap5) all reduce
 * to markersInHtml over the rendered source, so testing the statics covers
 * the gate's correctness.
 */
final class Bs5MarkersTest extends TestCase
{
    use AssertsBs5Markers;

    public function test_bs3_markers_are_detected_in_legacy_admin_markup(): void
    {
        $html = '<div class="panel panel-default navbar-inverse"><span class="caret"></span>'.
            '<button class="btn btn-default">X</button></div>';

        $found = self::markersInHtml($html, $this->bs3OnlyClasses);

        $this->assertContains('panel', $found);
        $this->assertContains('panel-default', $found);
        $this->assertContains('navbar-inverse', $found);
        $this->assertContains('caret', $found);
        $this->assertContains('btn-default', $found);
    }

    public function test_daisy_markers_are_detected(): void
    {
        $html = '<input class="input input-bordered"><dialog class="modal modal-box">'.
            '<table class="table table-zebra"></table><html data-theme="bcoem-brux">';

        $found = self::markersInHtml($html, $this->daisyOnlyMarkers);

        $this->assertContains('input-bordered', $found);
        $this->assertContains('modal-box', $found);
        $this->assertContains('table-zebra', $found);
        // data-* marker matches the attribute, not a bare substring.
        $this->assertContains('data-theme', $found);
    }

    public function test_bs5_markers_are_detected_in_clean_page(): void
    {
        $html = '<button data-bs-toggle="modal" data-bs-target="#x">'.
            '<button class="btn-close"></button><div class="container-xxl">'.
            '<label class="form-label">Name</label></div>';

        $found = self::markersInHtml($html, $this->bs5Markers);

        $this->assertContains('data-bs-toggle', $found);
        $this->assertContains('data-bs-target', $found);
        $this->assertContains('btn-close', $found);
        $this->assertContains('form-label', $found);
        $this->assertContains('container-xxl', $found);
    }

    public function test_clean_bootstrap5_page_has_no_bs3_or_daisy_markers(): void
    {
        $html = '<nav class="navbar navbar-expand-lg navbar-dark bg-dark">'.
            '<div class="container-xxl"><button class="btn btn-primary" data-bs-toggle="modal"'.
            'data-bs-target="#m">Open</button></div></nav>';

        $this->assertSame([], self::markersInHtml($html, $this->bs3OnlyClasses));
        $this->assertSame([], self::markersInHtml($html, $this->daisyOnlyMarkers));
    }

    public function test_shared_bootstrap_classes_are_not_false_positives(): void
    {
        // .btn and .modal exist in both BS3 and BS5 — they must NOT trip the
        // BS3-only detector (only the BS3-only sublist should).
        $html = '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#x">'.
            '<form class="form-control"></form>';

        $this->assertSame([], self::markersInHtml($html, $this->bs3OnlyClasses));
    }

    public function test_data_bootstrap_theme_is_not_confused_with_daisy_theme(): void
    {
        // BS5 uses data-bs-theme; daisy uses bare data-theme. A BS5 page with
        // data-bs-theme must not trip the daisy data-theme detector.
        $bs5 = '<html data-bs-theme="dark">';
        $this->assertFalse(self::markerInHtml($bs5, 'data-theme'));

        $daisy = '<html data-theme="bcoem-brux">';
        $this->assertTrue(self::markerInHtml($daisy, 'data-theme'));
    }
}
