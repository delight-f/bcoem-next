<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Results\BestBrewerStandings;
use App\Support\Results\ResultsRepository;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Results PDF/HTML (legacy output/results.output.php + winners sections).
 *
 * The legacy dashboard's results matrix is driven by five query params
 * (D1-01); every one is honoured here so each link renders its own document:
 *  - `go`     all | judging_scores | judging_scores_bos | best — which
 *             sections the document carries (header+BOS+best+winners, just the
 *             entries table, just BOS, or just the best-brewer standings).
 *  - `view`   default | winners — all received entries vs placed entries only.
 *             `pdf` and `html` are format selectors (the dashboard's PDF/HTML
 *             buttons); `html` bypasses the PDF pipeline.
 *  - `tb`     scores | none | bos — `scores` adds the numeric score column;
 *             anything else omits it.
 *  - `action` print | download | default — `download` sends the file as an
 *             attachment (StreamPdf's `$download` flag).
 *  - `psort`  table-entry-count-asc | table-entry-count-desc — reorders rows
 *             into table groups by group size; the default keeps the
 *             category sort.
 *
 * Winners grouping follows prefsWinnerMethod exactly like the public
 * results block: 1 = by category, 2 = by category+subcategory, else one
 * flat table. All data comes from ResultsRepository (winners rollup, all
 * received entries, BOS rows, best-brewer points) — no local re-implementation.
 */
final class ResultsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();
        $repo = ResultsRepository::current();

        $go = self::param($request, 'go', 'all');
        $view = self::param($request, 'view', 'default');
        $tb = self::param($request, 'tb', 'default');
        $action = self::param($request, 'action', 'print');
        $psort = self::param($request, 'psort', 'default');

        $winnersOnly = $view === 'winners';
        $showScores = $tb === 'scores';
        $download = $action === 'download';

        // view=winners selects the winners rollup; every other view value
        // (default, pdf, html) is the full received-entries report.
        $rows = self::sortRows($winnersOnly ? $repo->winners() : $repo->entries(), $psort);

        $data = [
            'contestName' => (string) ($ctx->contest['contestName'] ?? ''),
            'showBos' => in_array($go, ['all', 'judging_scores_bos'], true),
            'showBest' => in_array($go, ['all', 'best'], true)
                && ((int) ($ctx->prefs['prefsShowBestBrewer'] ?? 0) !== 0 || (int) ($ctx->prefs['prefsShowBestClub'] ?? 0) !== 0),
            'showWinners' => in_array($go, ['all', 'judging_scores'], true),
            'winnersOnly' => $winnersOnly,
            'showScores' => $showScores,
            'rows' => $rows,
            'lead' => $go === 'all' ? sprintf(
                'There were %d entries judged and %d registered participants, judges, and stewards.',
                DB::table('brewing')->where('brewReceived', 1)->count(),
                DB::table('brewer')->count(),
            ) : null,
            'bos' => $repo->bos(),
            'winnerMethod' => (string) $ctx->prefsStr('prefsWinnerMethod'),
            'bestBrewers' => BestBrewerStandings::forAwards($ctx)->brewerRows,
        ];

        if ($view === 'html') {
            return StreamPdf::html('outputs.results', $data, 'results.html', $download);
        }

        return StreamPdf::response('outputs.results', $data, 'results.pdf', $download);
    }

    /** A scalar query param, or $default when absent/non-scalar. */
    private static function param(Request $request, string $key, string $default): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * `table-entry-count-asc|desc` regroups the rows by their judging table
     * (entries with no table form their own group) and orders those groups by
     * size, so "By Table/Medal Group Entry Count" actually changes the order.
     * Any other psort keeps the repository's category ordering.
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    private static function sortRows(array $rows, string $psort): array
    {
        if ($psort !== 'table-entry-count-asc' && $psort !== 'table-entry-count-desc') {
            return $rows;
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) ($row->scoreTable ?? 0)][] = $row;
        }

        if ($groups === []) {
            return [];
        }

        uasort($groups, static fn (array $a, array $b): int => count($a) <=> count($b));
        if ($psort === 'table-entry-count-desc') {
            $groups = array_reverse($groups, true);
        }

        return array_values(array_merge(...array_values($groups)));
    }
}
