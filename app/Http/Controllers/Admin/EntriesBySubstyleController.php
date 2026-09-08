<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BrewController;
use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Entry count broken down by sub-style" report — spec §7 P5.5, ticket
 * P5.5. Legacy: admin/entries_by_substyle.admin.php.
 *
 * Count parity (verbatim predicates from the legacy .db.php include):
 *   - Logged   = WHERE brewCategorySort=<group> AND brewSubCategory=<num>
 *                AND brewConfirmed='1';
 *   - Paid & Received = same + brewPaid='1' AND brewReceived='1';
 *   - custom categories (numeric group >= 50) count by group alone, no
 *     subcategory filter.
 *
 * Display label DIVERGES cosmetically from legacy's per-set branches:
 * every row renders "<ltrim0 group>.<num> <style name>" (the AABC-style
 * '.' separator applied uniformly) instead of legacy's set-specific
 * concatenations.
 */
final class EntriesBySubstyleController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $typeNames = DB::table('style_types')->pluck('styleTypeName', 'id');

        $rows = BrewController::activeStyles($ctx)
            ->map(function (\stdClass $s) use ($typeNames): object {
                // Custom categories: numeric group >= 50 counts the whole
                // category, ignoring subcategory (legacy branch).
                $wholeCategory = is_numeric($s->brewStyleGroup)
                    && (int) $s->brewStyleGroup >= 50;

                return (object) [
                    'label' => ltrim((string) $s->brewStyleGroup, '0').'.'
                        .ltrim((string) $s->brewStyleNum, '0').' '.$s->brewStyle,
                    'category' => $s->brewStyleCategory ?: 'Custom Category',
                    'type' => $typeNames[$s->brewStyleType] ?? 'Other',
                    'logged' => self::count($s, $wholeCategory),
                    'paidReceived' => self::count($s, $wholeCategory, true),
                ];
            });

        $rawFilter = $request->query('filter');
        $filter = is_string($rawFilter) ? $rawFilter : 'default';
        if ($filter === 'no_zeros') {
            $rows = $rows->reject(fn (object $r): bool => $r->logged === 0)->values();
        }

        return view('admin.entries_by_substyle', [
            'ctx' => $ctx,
            'rows' => $rows,
            'totals' => (object) [
                'byType' => $rows->groupBy('type')
                    ->map(fn ($g): object => (object) [
                        'logged' => $g->sum('logged'),
                        'paidReceived' => $g->sum('paidReceived'),
                    ]),
                'logged' => $rows->sum('logged'),
                'paidReceived' => $rows->sum('paidReceived'),
            ],
            'filter' => $filter,
        ]);
    }

    private static function count(\stdClass $style, bool $wholeCategory, bool $paidReceived = false): int
    {
        $q = DB::table('brewing')->where('brewCategorySort', $style->brewStyleGroup);

        if (! $wholeCategory) {
            $q->where('brewSubCategory', $style->brewStyleNum);
        }

        if ($paidReceived) {
            $q->where('brewPaid', '1')->where('brewReceived', '1')->where('brewConfirmed', '1');
        } else {
            $q->where('brewConfirmed', '1');
        }

        return (int) $q->count();
    }
}
