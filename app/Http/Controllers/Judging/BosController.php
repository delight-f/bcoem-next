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
 * BOS round entry (spec §6 P4.4, ticket 04). Legacy:
 * admin/judging_scores_bos.admin.php + admin_judging_scores_bos.db.php +
 * process_judging_scores_bos.inc.php.
 *
 * Storage parity (ledger/scoring.md #3 + deliberate weirdness):
 *   - Eligibility by styleTypeBOSMethod via Place::bosEligiblePlaces() —
 *     explicit value list, never string >=.
 *   - "Mead/Cider" style type combines scoreTypes 2 and 3
 *     (admin_judging_scores_bos.db.php:26).
 *   - BOS scorePlace is varchar(3): '5' is written for HM (the legacy form's
 *     option value); read logic treats a literal 'HM' as equivalent, but the
 *     public winners filter IN ('1'..'5') only sees '5'.
 *   - Per-row write semantics (:24-71): place posted + row exists → update;
 *     place posted + no row → insert; place cleared + row exists → DELETE.
 */
final class BosController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $types = DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get();

        return view('judging.bos', [
            'ctx' => TenantContext::load(),
            // Same list feeds the "Add or Update..." and Print dropdowns
            // (admin/judging_scores_bos.admin.php default view).
            'types' => $types,
            'groups' => collect($types)->map(fn (object $type): object => (object) [
                'type' => $type,
                'rows' => self::eligible((int) $type->id),
            ])->all(),
        ]);
    }

    /** Add/update form for one BOS style type ("enter" in legacy). */
    public function edit(Request $request, int $styleType): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $type = DB::table('style_types')->where('id', $styleType)->first();
        if ($type === null) {
            return redirect('/admin/judging/bos');
        }

        $maxBos = max(1, (int) TenantContext::load()->judgingStr('jPrefsMaxBOS'));

        return view('judging.bos-form', [
            'ctx' => TenantContext::load(),
            'type' => $type,
            'rows' => self::eligible($styleType),
            'maxBos' => $maxBos,
        ]);
    }

    public function update(Request $request, int $styleType): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $maxBos = max(5, (int) TenantContext::load()->judgingStr('jPrefsMaxBOS'));
        /** @var list<string> $keys */
        $keys = array_map(strval(...), (array) $request->input('score_id', []));

        DB::transaction(function () use ($request, $keys, $maxBos): void {
            foreach ($keys as $key) {
                $place = trim((string) $request->input("scorePlace{$key}", ''));
                if ($place !== '' && (! ctype_digit($place) || (int) $place < 1 || (int) $place > $maxBos)) {
                    continue; // outside the legacy select's option set — ignore like an empty place
                }

                // Hidden id present ⇔ the entry already had a BOS row (scorePrevious Y/N).
                $existingId = $request->input("id{$key}");

                if ($place !== '' && $existingId !== null && ctype_digit((string) $existingId)) {
                    DB::table('judging_scores_bos')->where('id', (int) $existingId)->update([
                        'eid' => (int) $request->input("eid{$key}"),
                        'bid' => self::blankToNull(self::str($request, "bid{$key}")),
                        'scoreEntry' => self::blankToNull(self::str($request, "scoreEntry{$key}")),
                        'scorePlace' => $place,
                        'scoreType' => self::blankToNull(self::str($request, "scoreType{$key}")),
                    ]);
                } elseif ($place !== '') {
                    DB::table('judging_scores_bos')->insert([
                        'eid' => (int) $request->input("eid{$key}"),
                        'bid' => self::blankToNull(self::str($request, "bid{$key}")),
                        'scoreEntry' => self::blankToNull(self::str($request, "scoreEntry{$key}")),
                        'scorePlace' => $place,
                        'scoreType' => self::blankToNull(self::str($request, "scoreType{$key}")),
                    ]);
                } elseif ($existingId !== null && ctype_digit((string) $existingId)) {
                    DB::table('judging_scores_bos')->where('id', (int) $existingId)->delete();
                }
            }
        });

        return redirect('/admin/judging/bos');
    }

    /**
     * Entries eligible for a style type's BOS round: judging_scores rows of
     * the right scoreType whose scorePlace is in the method's explicit list,
     * joined with any existing judging_scores_bos row per entry.
     *
     * @return list<object>
     */
    private static function eligible(int $styleTypeId): array
    {
        $type = DB::table('style_types')->where('id', $styleTypeId)->first(['styleTypeName', 'styleTypeBOSMethod']);
        if ($type === null) {
            return [];
        }

        $query = DB::table('judging_scores as js')
            ->leftJoin('brewing as b', 'js.eid', '=', 'b.id')
            ->leftJoin('judging_tables as t', 'js.scoreTable', '=', 't.id')
            ->leftJoin('judging_scores_bos as jsb', 'jsb.eid', '=', 'js.eid');

        // Mead/Cider combines the cider (2) and mead (3) style types.
        if ($type->styleTypeName === 'Mead/Cider') {
            $query->whereIn('js.scoreType', ['2', '3']);
        } else {
            $query->where('js.scoreType', (string) $styleTypeId);
        }

        $rows = $query
            ->whereIn('js.scorePlace', Place::bosEligiblePlaces((int) $type->styleTypeBOSMethod))
            ->get([
                'js.id as scoreId', 'js.eid', 'js.bid', 'js.scoreEntry', 'js.scorePlace',
                'js.scoreType', 'js.scoreTable',
                'b.brewName', 'b.brewJudgingNumber', 'b.brewCategorySort', 'b.brewSubCategory',
                't.tableNumber', 't.tableName',
                'jsb.id as bosId', 'jsb.scorePlace as bosPlace',
            ])
            ->all();

        return array_values($rows);
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
