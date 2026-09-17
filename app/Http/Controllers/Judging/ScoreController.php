<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Results\Place;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Score entry per table (spec §6 P4.4, ticket 04). Legacy:
 * admin/judging_scores.admin.php + process_judging_scores.inc.php.
 *
 * Storage parity (ledger/scoring.md #4/#5):
 *   - Saving a table WIPES all judging_scores rows for it, then re-inserts
 *     every posted row — so "partial edits" preserve other columns because
 *     the form re-posts the whole table, not because of an UPDATE.
 *   - A row is written only when ANY of scoreEntry/scorePlace/scoreMiniBOS
 *     is posted non-empty (:58).
 *   - Empty mini-BOS checkbox writes 0, never NULL (:33-34).
 *   - scorePlace '5' is the storage code for HM (FLOAT column there and in
 *     the baseline schema — literal 'HM' would vanish from the public
 *     winners filter IN ('1'..'5'), ledger/winners-display.md #3).
 *   - Every text column goes through blank_to_null.
 *
 * Winner-delay (ledger/winners-display.md): the legacy write path sets no
 * timestamps at all — reveal gating reads preferences.prefsWinnerDelay
 * (process_prefs/process_dates), untouched here. Parity is preserved by
 * storing places as '1'..'5' exactly like legacy.
 */
final class ScoreController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $scores = DB::table('judging_scores as js')
            ->leftJoin('brewing as b', 'js.eid', '=', 'b.id')
            ->leftJoin('judging_tables as t', 'js.scoreTable', '=', 't.id')
            ->orderBy('js.scoreTable')
            ->orderBy('b.brewCategorySort')
            ->orderByDesc('js.scoreEntry')
            ->get([
                'js.id', 'js.eid', 'js.scoreTable', 'js.scoreEntry', 'js.scorePlace', 'js.scoreMiniBOS',
                'b.brewName', 'b.brewCategorySort', 'b.brewSubCategory',
                't.tableNumber', 't.tableName',
            ]);

        // Legacy control set (admin/judging_scores.admin.php, default view):
        // the dropdown lists every judging table; the status line counts ALL
        // judging_scores rows (admin_common.db.php:75-82) against entries
        // marked paid AND received (total_paid_received('judging_scores',0)).
        $ctx = TenantContext::load();

        return view('judging.scores', [
            'ctx' => $ctx,
            'scores' => $scores,
            'tables' => DB::table('judging_tables')->orderBy('tableNumber')->get(['id', 'tableNumber', 'tableName']),
            // Tables that already have scores (legacy score_table_choose,
            // lib/admin.lib.php:445 picks action=edit for them).
            'scoredTableIds' => DB::table('judging_scores')->distinct()->pluck('scoreTable')->map(fn ($v) => (int) $v)->all(),
            'bosTypes' => DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get(['id', 'styleTypeName']),
            'scoresEntered' => DB::table('judging_scores')->count(),
            'paidReceived' => DB::table('brewing')->where('brewPaid', '1')->where('brewReceived', '1')->count(),
            'evalOn' => $ctx->prefsStr('prefsEval') === '1',
        ]);
    }

    /**
     * Add/edit form for one table's received entries. Legacy used separate
     * add/edit actions; both render the same grid, so one route serves them.
     */
    public function edit(Request $request, int $table): View|RedirectResponse
    {
        $tableRow = DB::table('judging_tables')->where('id', $table)->first();
        if ($tableRow === null) {
            return redirect('/admin/judging/scores');
        }

        return view('judging.score-form', [
            'ctx' => TenantContext::load(),
            'table' => $tableRow,
            'entries' => $this->tableEntries($tableRow),
        ]);
    }

    public function update(Request $request, int $table): RedirectResponse
    {
        DB::transaction(function () use ($request, $table): void {
            // First, wipe out all previously recorded scores for the table.
            DB::table('judging_scores')->where('scoreTable', $table)->delete();

            /** @var list<string> $ids */
            $ids = array_map(strval(...), (array) $request->input('score_id', []));

            foreach ($ids as $key) {
                $eid = (int) $request->input("eid{$key}");
                // Second, get rid of any duplicates across tables (:37-55).
                DB::table('judging_scores')->where('eid', $eid)->delete();

                $entry = self::str($request, "scoreEntry{$key}");
                $place = self::str($request, "scorePlace{$key}");
                $miniRaw = self::str($request, "scoreMiniBOS{$key}");

                // Row written when ANY of entry/place/miniBOS posted non-empty (:58).
                if ($entry === '' && $place === '' && $miniRaw === '') {
                    continue;
                }

                DB::table('judging_scores')->insert([
                    'eid' => self::blankToNull($eid === 0 ? null : (string) $eid),
                    'bid' => self::blankToNull(self::str($request, "bid{$key}")),
                    'scoreTable' => self::blankToNull((string) $table),
                    'scoreEntry' => self::blankToNull($entry),
                    // '5' stays the storage code for HM — never literal 'HM'.
                    'scorePlace' => self::blankToNull($place),
                    'scoreType' => self::blankToNull(self::str($request, "scoreType{$key}")),
                    // Empty checkbox ⇒ 0 written, not NULL (#4).
                    'scoreMiniBOS' => $miniRaw === '' ? 0 : 1,
                ]);
            }
        });

        return redirect('/admin/judging/scores');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        DB::table('judging_scores')->delete($id);

        return redirect('/admin/judging/scores');
    }

    /**
     * Received entries eligible for this table, keyed by style pairing:
     * brewing.brewCategorySort/brewSubCategory must match each style in the
     * table's CSV (admin_judging_scores.db.php). Each row carries its
     * existing judging_scores data (null when unscored) plus scoreType.
     *
     * @return list<object{
     *   id: int, bid: string|null, brewName: string|null,
     *   brewCategorySort: string|null, brewSubCategory: string|null,
     *   brewJudgingNumber: string|null, styleDisplay: string,
     *   scoreType: string, score: object|null
     * }>
     */
    private function tableEntries(\stdClass $tableRow): array
    {
        $styleIds = array_values(array_unique(array_filter(explode(',', (string) $tableRow->tableStyles))));
        $entries = [];

        foreach ($styleIds as $styleId) {
            $style = DB::table('styles')->where('id', $styleId)->first(['brewStyleGroup', 'brewStyleNum', 'brewStyle', 'brewStyleType']);
            if ($style === null) {
                continue;
            }

            $rows = DB::table('brewing')
                ->where('brewCategorySort', $style->brewStyleGroup)
                ->where('brewSubCategory', $style->brewStyleNum)
                ->where('brewReceived', 1)
                ->get();

            foreach ($rows as $row) {
                $entries[] = (object) [
                    'id' => (int) $row->id,
                    'bid' => $row->brewBrewerID,
                    'brewName' => $row->brewName,
                    'brewCategorySort' => $row->brewCategorySort,
                    'brewSubCategory' => $row->brewSubCategory,
                    'brewJudgingNumber' => $row->brewJudgingNumber,
                    'styleDisplay' => trim((string) $style->brewStyleGroup.':'.(string) $style->brewStyleNum.' '.(string) $style->brewStyle),
                    'scoreType' => self::scoreTypeId((string) $style->brewStyleType),
                    'score' => DB::table('judging_scores')->where('eid', (int) $row->id)->first(),
                ];
            }
        }

        usort($entries, fn (object $a, object $b) => [(string) $a->brewCategorySort, (string) $a->brewSubCategory] <=> [(string) $b->brewCategorySort, (string) $b->brewSubCategory]);

        return $entries;
    }

    /**
     * Legacy style_type($type, "1", "bcoe") (common.lib.php:2165):
     * Mead→3, Cider→2, everything else beer→1; custom numeric ids through.
     */
    private static function scoreTypeId(string $brewStyleType): string
    {
        return match ($brewStyleType) {
            'Mead' => '3',
            'Cider' => '2',
            'Ale', 'Lager', 'Mixed' => '1',
            default => $brewStyleType,
        };
    }

    private static function str(Request $request, string $key): string
    {
        return trim((string) $request->input($key, ''));
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
