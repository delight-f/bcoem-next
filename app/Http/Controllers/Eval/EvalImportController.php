<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use App\Support\Eval\EvalConsensus;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * import_scores (spec P4.6): runs the consensus import
 * (App\Support\Eval\EvalConsensus) and reports the legacy JSON envelope.
 *
 * Legacy gated the ajax write on userLevel<=1 + same-origin referer; the
 * port gates in-controller (userLevel<=1) and relies on the web group's
 * CSRF protection for the POST — hardening noted in routes/web.php.
 */
final class EvalImportController extends Controller
{
    public function __construct(
        private readonly EvalConsensus $consensus,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('eval.import', ['ctx' => TenantContext::load()]);
    }

    public function import(Request $request): JsonResponse|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $report = $this->consensus->import();

        // The legacy endpoint was called over XHR and echoed a JSON envelope.
        // The port's confirm/dashboard forms are plain POSTs, so a browser
        // would render that raw JSON as the document. Keep the envelope for
        // programmatic callers and redirect with a summary otherwise.
        if (! $request->expectsJson()) {
            return redirect()
                ->route('eval.dashboard')
                ->with('status', self::summary($report));
        }

        // Same keys the legacy ajax endpoint echoed (minus the dropped
        // flagged bucket, superseded by the ledger's MAX-wins pin).
        return response()->json([
            'status' => (string) $report['status'],
            'scores_imported_count' => (string) $report['imported'],
            'scores_updated_count' => (string) $report['updated'],
            'singles' => $report['singles'],
            'scored_places_discrepency' => implode(',', $report['discrepancies']),
            'scored_places_discrepency_count' => count($report['discrepancies']),
        ]);
    }

    /**
     * Human summary of an import report for the redirect/flash path.
     *
     * @param  array<string, mixed>  $report
     */
    private static function summary(array $report): string
    {
        $singles = (array) $report['singles'];
        $discrepancies = (array) $report['discrepancies'];

        $message = sprintf(
            'Import complete: %d score(s) imported, %d updated.',
            (int) $report['imported'],
            (int) $report['updated'],
        );

        if ($singles !== []) {
            $message .= ' Skipped '.count($singles).' entry(s) with a single evaluation: '.implode(', ', $singles).'.';
        }

        if ($discrepancies !== []) {
            $message .= ' Scored-place discrepancies: '.implode(',', $discrepancies).'.';
        }

        return $message;
    }
}
