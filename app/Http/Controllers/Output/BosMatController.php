<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Results\Place;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * BOS cup mats — 2×3 tiles per page (legacy output/bos_mat.output.php
 * + db/output_bos_mat.db.php).
 *
 * Modes via ?action=, mirroring legacy:
 *  - default   → one mat group per style type with styleTypeBOS='Y'
 *                (or the single ?view= style-type id); entries placed in
 *                the type's styleTypeBOSMethod places; scoreType=4 groups
 *                merge Mead+Cider (scoreType 2 or 3) like legacy's special
 *                case.
 *  - mini-bos  → one group per judging table (or ?view= table id) of
 *                entries with scoreMiniBOS='1'.
 *  - pro-am    → like default for one style type, but place-filtered by
 *                ?sort=1|2|3.
 *  - blank     → a page of six blank mats (legacy $action == "blank").
 *
 * Mirrored quirks:
 *  - Tile footer shows the entry id when filter=entry, else the judging
 *    number, both zero-padded to six digits.
 *  - prefsWinnerMethod=0 labels tiles by judging table; >0 by category.
 *  - A brewer flagged brewerProAm=1 gets legacy's "NOT ELIGIBLE" note.
 *  - Legacy renders one table per group, each padded to 6 mats (2×3) and
 *    page-break-after; an empty group still emits an empty mat page.
 *  - Divergence: the BA style-set tile variant (style_convert() heading)
 *    is not reproduced — BA tenants get the standard category+subcategory
 *    label. Revisit if a BA tenant actually prints mats.
 */
final class BosMatController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        $actionParam = $request->query('action', 'default');
        $viewParam = $request->query('view', 'default');
        $filterParam = $request->query('filter', '');
        $action = is_string($actionParam) ? $actionParam : 'default';
        $view = is_string($viewParam) ? $viewParam : 'default';
        $filter = is_string($filterParam) ? $filterParam : '';

        $tables = DB::table('judging_tables')->get(['id', 'tableNumber', 'tableName'])
            ->keyBy('id')
            ->map(fn ($t): string => 'Table '.$t->tableNumber.': '.$t->tableName);

        // Blank-page heading tracks ?view= ("mini-bos" / "pro-am"),
        // exactly like legacy's blank branch.
        $heading = match (true) {
            $action === 'mini-bos' => 'Mini-BOS',
            $action === 'pro-am' => 'Pro-Am/Scale-Up',
            $action === 'blank' && $view === 'mini-bos' => 'Mini-BOS',
            $action === 'blank' && $view === 'pro-am' => 'Pro-Am/Scale-Up',
            default => 'Best of Show',
        };

        /** @var list<array{title: string|null, type: int|null, rows: list<object>}> $groups */
        $groups = [];

        if ($action === 'blank') {
            $groups[] = ['title' => null, 'type' => null, 'rows' => []];
        } elseif ($action === 'mini-bos') {
            $ids = $view === 'default'
                ? DB::table('judging_tables')->orderBy('id')->pluck('id')->all()
                : [(int) $view];

            foreach ($ids as $tableId) {
                $groups[] = [
                    'title' => '*** Mini-BOS ***',
                    'type' => null,
                    'rows' => self::rows(fn ($q) => $q
                        ->where('js.scoreTable', $tableId)
                        ->where('js.scoreMiniBOS', '1')),
                ];
            }
        } else {
            $sortParam = $request->query('sort', '');
            $sort = is_string($sortParam) ? $sortParam : '';

            $types = DB::table('style_types')->get(['id', 'styleTypeName', 'styleTypeBOS', 'styleTypeBOSMethod'])
                ->keyBy('id');
            $ids = $view === 'default' ? $types->keys()->sort()->values()->all() : [(int) $view];

            foreach ($ids as $typeId) {
                $type = $types->get($typeId);
                // Legacy gates every non-mini-bos group (including pro-am)
                // on styleTypeBOS == 'Y'.
                if ($type === null || $type->styleTypeBOS !== 'Y') {
                    continue;
                }

                // Legacy applies no place restriction when ?sort= is absent
                // or unrecognized on a pro-am mat.
                $places = $action === 'pro-am'
                    ? (in_array($sort, ['1', '2', '3'], true) ? array_slice(['1', '2', '3'], 0, (int) $sort) : null)
                    : Place::bosEligiblePlaces((int) $type->styleTypeBOSMethod);

                // Legacy type-4 group merges the Mead (3) and Cider (2) rounds.
                // Pro-am keeps legacy's stray "*" in the heading quirk.
                $groups[] = [
                    'title' => $action === 'pro-am'
                        ? '*** Pro-Am: '.$type->styleTypeName.' * ***'
                        : '*** Best of Show: '.$type->styleTypeName.' ***',
                    'type' => (int) $typeId,
                    'rows' => self::rows(function ($q) use ($typeId, $places) {
                        if ($typeId == 4) {
                            $q->whereIn('js.scoreType', ['2', '3']);
                        } else {
                            $q->where('js.scoreType', $typeId);
                        }

                        return $places === null ? $q : $q->whereIn('js.scorePlace', $places);
                    }),
                ];
            }
        }

        return StreamPdf::response('outputs.bos_mat', [
            'groups' => $groups,
            'blank' => $action === 'blank',
            'heading' => $heading,
            'action' => $action,
            'view' => $view,
            'tables' => $tables,
            'labelByTable' => (string) $ctx->prefsStr('prefsWinnerMethod') === '0',
            'showEntryNumber' => $filter === 'entry',
            // Category → "1A,1B,…" expansion for the tile subheading when
            // tiles are labeled by category. Reads the styles table directly
            // instead of porting style_convert()'s per-style-set switch
            // tables; the BA-set variant stays unported (class docblock).
            'subcats' => DB::table('styles')->orderBy('brewStyleNum')->get(['brewStyleGroup', 'brewStyleNum'])
                ->groupBy('brewStyleGroup')
                ->map(fn ($group) => $group->pluck('brewStyleNum')->implode(','))
                ->all(),
            'anyTiles' => array_sum(array_map(fn (array $g): int => count($g['rows']), $groups)) > 0,
        ], 'bos_mat.pdf');
    }

    /**
     * Placed entries for one mat group, ordered category → subcategory
     * like output_bos_mat.db.php. The `$filter` closure applies the group's
     * scoreType/place/mini-bos selection; no brewReceived restriction exists
     * in the legacy query.
     *
     * @param  callable(Builder): mixed  $filter
     * @return list<object>
     */
    private static function rows(callable $filter): array
    {
        $query = DB::table('judging_scores as js')
            ->join('brewing as b', 'js.eid', '=', 'b.id')
            ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
            ->orderBy('b.brewCategorySort')
            ->orderBy('b.brewSubCategory');

        $filter($query);

        return array_values($query->get([
            'js.scoreTable',
            'b.id',
            'b.brewJudgingNumber',
            'b.brewCategory',
            'b.brewCategorySort',
            'b.brewSubCategory',
            'b.brewStyle',
            'b.brewInfo',
            'b.brewInfoOptional',
            'b.brewComments',
            'b.brewMead1',
            'b.brewMead2',
            'b.brewMead3',
            'b.brewPossAllergens',
            'br.brewerProAm',
        ])->all());
    }
}
