<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BrewController;
use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Entry count broken down by style" report — spec §7 P5.5, ticket P5.5.
 * Legacy: admin/entries_by_style.admin.php + entries_by_style.db.php.
 *
 * Count parity (entries_by_style.db.php, verbatim predicates):
 *   - Logged   = COUNT(*) WHERE brewCategorySort=<cat> AND brewConfirmed='1';
 *   - Paid & Received = same + brewPaid='1' AND brewReceived='1'.
 *
 * Category source DIVERGES deliberately from legacy's hardcoded
 * $style_sets constant arrays: the port derives the category list from
 * the styles table itself (active set + organizer selection via
 * BrewController::activeStyles). Zero-entry categories appear only when
 * the set's styles are selected — the selection IS the competition's
 * style list in the port. Totals classify each category through its
 * styles.brewStyleType row (baseline_style_types names) instead of the
 * legacy beer_end/mead_array/cider_array constants.
 */
final class EntriesByStyleController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();

        // One row per category group, in styles-table order.
        $styles = BrewController::activeStyles($ctx);
        $groups = $styles->groupBy('brewStyleGroup');

        $typeNames = DB::table('style_types')->pluck('styleTypeName', 'id');

        $rows = $groups->map(function (Collection $group, string $cat) use ($typeNames): object {
            $first = $group->first();

            return (object) [
                'category' => $cat,
                // Display pad matches legacy sprintf('%02d') for numeric cats.
                'label' => ctype_digit($cat) ? str_pad($cat, 2, '0', STR_PAD_LEFT) : $cat,
                'name' => ($first !== null ? $first->brewStyleCategory : null) ?: 'Custom Category',
                'type' => $typeNames[$first !== null ? $first->brewStyleType : ''] ?? 'Other',
                'logged' => self::count($cat),
                'paidReceived' => self::count($cat, true),
            ];
        })->values();

        $rawFilter = $request->query('filter');
        $filter = is_string($rawFilter) ? $rawFilter : 'default';
        if ($filter === 'no_zeros') {
            $rows = $rows->reject(fn (object $r): bool => $r->logged === 0)->values();
        }

        return view('admin.entries_by_style', [
            'ctx' => $ctx,
            'rows' => $rows,
            // Totals rollup over the visible rows (legacy sums the same).
            'totals' => (object) [
                'byType' => $rows->groupBy('type')
                    ->map(fn (Collection $g): object => (object) [
                        'logged' => $g->sum('logged'),
                        'paidReceived' => $g->sum('paidReceived'),
                    ]),
                'logged' => $rows->sum('logged'),
                'paidReceived' => $rows->sum('paidReceived'),
            ],
            'filter' => $filter,
        ]);
    }

    private static function count(string $categorySort, bool $paidReceived = false): int
    {
        $q = DB::table('brewing')->where('brewCategorySort', $categorySort);

        if ($paidReceived) {
            // Legacy predicate order: paid + received (+ confirmed here).
            $q->where('brewPaid', '1')->where('brewReceived', '1')->where('brewConfirmed', '1');
        } else {
            $q->where('brewConfirmed', '1');
        }

        return (int) $q->count();
    }
}
