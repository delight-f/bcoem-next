<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Issue 19: report PDFs whose data source is empty must not render a blank
 * page (which reads as a silent failure) — they state "No data entered."
 *
 * Rendered directly rather than through dompdf/pdftotext so the check is
 * deterministic and browser/poppler independent.
 */
final class OutputEmptyStateTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function emptyReports(): array
    {
        return [
            'outputs.labels' => ['labels' => [], 'perSheet' => 30],
            'outputs.labels_box' => ['labels' => [], 'perSheet' => 30],
            'outputs.labels_quicksort' => ['cells' => []],
            'outputs.labels_round' => ['cells' => [], 'psort' => 'default'],
            'outputs.labels_nametag' => ['labels' => []],
            'outputs.bottle_label' => ['cells' => [], 'info' => '', 'perPage' => 9, 'contest' => 'C', 'large' => false, 'barcodeQr' => false],
            'outputs.sorting' => ['categories' => [], 'cheat' => false, 'showJudging' => false],
            'outputs.shipping-label' => ['brewers' => [], 'shippingName' => 'N', 'shippingAddress' => 'A'],
            'outputs.dropoff' => ['locations' => [], 'total' => 0, 'mode' => 'default'],
            'outputs.participant-summary' => ['participants' => [], 'contestName' => 'C', 'receivedCount' => 0, 'organizer' => null],
            'outputs.participant-entries-list' => ['rows' => []],
            'outputs.post-judge-inventory' => ['rows' => [], 'contestName' => 'C', 'withScores' => false],
        ];
    }

    public function test_empty_report_views_render_no_data_placeholder(): void
    {
        foreach ($this->emptyReports() as $view => $data) {
            $html = View::make($view, $data)->render();
            $this->assertStringContainsString('No data entered.', $html, "{$view} is blank with no data");
        }
    }
}
