<?php

declare(strict_types=1);

namespace App\Support\Outputs;

use Dompdf\Dompdf;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Shared PDF pipeline for all Slice D outputs (P5.1 decision: dompdf
 * replaces legacy's vendored FPDF — outputs are Blade templates, not
 * coordinate-drawing code).
 */
final class StreamPdf
{
    /**
     * Render a Blade view to an inline PDF response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function response(string $view, array $data, string $filename, bool $download = false): Response
    {
        $html = view($view, $data)->render();

        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();

        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
        ]);
    }

    /** Render a Blade view straight to raw PDF bytes (tests).
     *
     * @param  array<string, mixed>  $data
     */
    public static function bytes(string $view, array $data): string
    {
        $html = view($view, $data)->render();
        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();

        return (string) $pdf->output();
    }
}
