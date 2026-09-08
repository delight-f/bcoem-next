<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Results\ResultsRepository;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Results PDF (legacy output/results.output.php + winners sections).
 *
 * Modes via ?go=, mirroring legacy's go/action pairs:
 *  - judging_scores  → winning entries only
 *  - judging_scores_bos → best-of-show placements only
 *  - best            → best brewer / best club standings (shown only when
 *                      prefsShowBestBrewer or prefsShowBestClub is on)
 *  - all (default)   → contest header with received-entry and participant
 *                      counts, then BOS, then best brewer, then winners —
 *                      legacy "go=all" ordering.
 *
 * Winners grouping follows prefsWinnerMethod exactly like the public
 * results block: 1 = by category, 2 = by category+subcategory, else one
 * flat table. All data comes from ResultsRepository (winners rollup,
 * BOS rows, best-brewer points) — no local re-implementation.
 */
final class ResultsController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $repo = ResultsRepository::current();

        $goQuery = $request->query('go', 'all');
        $go = is_string($goQuery) ? $goQuery : 'all';

        return StreamPdf::response('outputs.results', [
            'contestName' => (string) ($ctx->contest['contestName'] ?? ''),
            'showBos' => in_array($go, ['all', 'judging_scores_bos'], true),
            'showBest' => in_array($go, ['all', 'best'], true)
                && ((int) ($ctx->prefs['prefsShowBestBrewer'] ?? 0) !== 0 || (int) ($ctx->prefs['prefsShowBestClub'] ?? 0) !== 0),
            'showWinners' => in_array($go, ['all', 'judging_scores'], true),
            'lead' => $go === 'all' ? sprintf(
                '%d entries received from %d participants.',
                DB::table('brewing')->where('brewReceived', 1)->count(),
                DB::table('brewer')->count(),
            ) : null,
            'bos' => $repo->bos(),
            'winners' => $repo->winners(),
            'winnerMethod' => (string) $ctx->prefsStr('prefsWinnerMethod'),
            'bestBrewers' => $repo->bestBrewers((string) $ctx->prefsStr('prefsBestBrewerPointsMethod'), 'flat'),
        ], 'results.pdf');
    }
}
