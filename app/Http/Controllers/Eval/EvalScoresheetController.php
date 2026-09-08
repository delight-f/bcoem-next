<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Eval\Descriptors;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Scoresheet rendering (spec P4.6): full (generic BJCP) and structured
 * variants selected by judging_preferences.jPrefsScoresheet — checklist
 * (2) falls back to full, NW-cider variant dropped per ledger. Output
 * views render the saved evaluations for an entry.
 *
 * Legacy db.eval.php resolved the entry's style row with a version-aware
 * query; the port prefers the evaluation's stored style id and otherwise
 * matches on category/subcategory only.
 */
final class EvalScoresheetController extends Controller
{
    public function show(Request $request, int $entryId): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $ctx = TenantContext::load();
        $archive = EvalDashboardController::archiveSuffix($request);

        $entry = DB::table('brewing'.$archive)->where('id', $entryId)->first();
        if ($entry === null) {
            abort(404);
        }

        // Edit-in-place: the judge's own existing evaluation for this
        // entry. Runtime reads go through Laravel DB (EvalConsensus note).
        $evaluation = DB::table('evaluation')
            ->where('eid', $entryId)
            ->orderByDesc('id')
            ->get()
            ->first(static fn ($row) => $row->evalJudgeInfo === $user->id || $user->isAdmin());

        [$style, $styleType] = $this->styleFor($entry, $evaluation);
        $variant = in_array((int) $ctx->judgingStr('jPrefsScoresheet'), [3, 4], true) ? 'structured' : 'full';

        return view('eval.scoresheet', [
            'ctx' => $ctx,
            'archive' => $archive,
            'entry' => $entry,
            'evaluation' => $evaluation,
            'style' => $style,
            'styleType' => $styleType,
            'points' => Descriptors::points($styleType),
            'descriptors' => Descriptors::descriptors($styleType),
            'ticks' => Descriptors::ticks($styleType),
            'flaws' => Descriptors::flaws($styleType),
            'variant' => $variant,
        ]);
    }

    public function output(Request $request, int $entryId): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $ctx = TenantContext::load();
        $archive = EvalDashboardController::archiveSuffix($request);

        $entry = DB::table('brewing'.$archive)->where('id', $entryId)->first();
        if ($entry === null) {
            abort(404);
        }

        $evaluations = DB::table('evaluation')
            ->where('eid', $entryId)
            ->orderBy('id')
            ->get()
            ->filter(static fn ($row) => $row->evalJudgeInfo === (int) $user->id || $user->isAdmin())
            ->values()
            ->all();

        [$style, $styleType] = $this->styleFor($entry, $evaluations[0] ?? null);

        return view('eval.output', [
            'ctx' => $ctx,
            'entry' => $entry,
            'evaluations' => $evaluations,
            'style' => $style,
            'points' => Descriptors::points($styleType),
        ]);
    }

    /**
     * Style row + numeric style type for the entry (db.eval.php shape).
     *
     * @return array{0: object|null, 1: int}
     */
    private function styleFor(\stdClass $entry, ?\stdClass $evaluation): array
    {
        $style = null;

        if ($evaluation !== null && $evaluation->evalStyle !== null) {
            $style = DB::table('styles')->where('id', $evaluation->evalStyle)->first();
        }

        if ($style === null) {
            $style = DB::table('styles')
                ->where('brewStyleGroup', $entry->brewCategorySort)
                ->where('brewStyleNum', $entry->brewSubCategory)
                ->orderBy('id')
                ->first();
        }

        $rawType = $style === null ? null : ($style->brewStyleType ?? null);
        $type = is_numeric($rawType) ? (int) $rawType : 1;

        return [$style, $type];
    }
}
