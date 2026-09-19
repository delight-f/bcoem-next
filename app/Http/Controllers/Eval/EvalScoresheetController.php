<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Eval\Descriptors;
use App\Support\Eval\EvalConsensus;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Scoresheet rendering (spec P4.6): full (generic BJCP), checklist (beer
 * only), structured, and NW Cider structured variants selected by
 * judging_preferences.jPrefsScoresheet and the entry's style type —
 * mapping follows eval/scoresheet.eval.php:121-145,292-306.
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

        [$style, $styleType] = self::styleFor($entry, $evaluation);
        $variant = self::variantFor((int) $ctx->judgingStr('jPrefsScoresheet'), $styleType);

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
            'checklist' => Descriptors::checklist(),
            'nwCider' => Descriptors::nwCider(),
            'variant' => $variant,
            'displayId' => self::displayId($entry, $ctx),
        ]);
    }

    /**
     * Legacy variant resolution (eval/scoresheet.eval.php:121-145,292-306):
     * the checklist sheet is beer-only, and only a cider entry gets the NW
     * Cider structured form under preference 4.
     */
    public static function variantFor(int $pref, int $styleType): string
    {
        return match ($pref) {
            2 => in_array($styleType, [2, 3], true) ? 'full' : 'checklist',
            3 => 'structured',
            4 => $styleType === 2 ? 'nw-cider' : 'structured',
            default => 'full',
        };
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

        [$style, $styleType] = self::styleFor($entry, $evaluations[0] ?? null);

        /** @var list<float|int|string|null> $finalScores */
        $finalScores = array_values(array_map(static fn ($row) => $row->evalFinalScore, $evaluations));

        return view('eval.output', [
            'ctx' => $ctx,
            'archive' => $archive,
            'entry' => $entry,
            'evaluations' => $evaluations,
            'style' => $style,
            'points' => Descriptors::points($styleType),
            'displayId' => self::displayId($entry, $ctx),
            'disagree' => EvalConsensus::scoresDisagree($finalScores),
            'dispersion' => EvalConsensus::scoreDispersion(),
            // Candidate destinations for the admin's move action; empty for
            // everyone else, which is also what hides the block (issue #1756).
            'moveEntries' => $user->isAdmin() ? self::moveEntries($archive) : [],
        ]);
    }

    /**
     * Every entry of the competition, for the admin move picker.
     *
     * @return array<int, \stdClass>
     */
    private static function moveEntries(string $archive): array
    {
        return DB::table('brewing'.$archive)
            ->orderBy('brewJudgingNumber')
            ->orderBy('id')
            ->get(['id', 'brewJudgingNumber', 'brewName', 'brewCategorySort', 'brewSubCategory', 'brewStyle'])
            ->all();
    }

    /**
     * The identifier shown to judges ("Scoresheet Unique Identifier",
     * preferences.prefsDisplaySpecial): 'E' = the entry id zero-padded to six
     * digits; 'J' (default) = the entry's six-character judging number, with
     * the padded id as a fallback when none is assigned (B3-07).
     */
    private static function displayId(\stdClass $entry, TenantContext $ctx): string
    {
        if ((string) $ctx->prefsStr('prefsDisplaySpecial') === 'E') {
            return str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT);
        }

        $judging = trim((string) ($entry->brewJudgingNumber ?? ''));

        return $judging !== '' ? $judging : str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Style row + numeric style type for the entry (db.eval.php shape).
     * Public because the admin move action (issue #1756) repoints
     * evalStyle with the same rule.
     *
     * @return array{0: \stdClass|null, 1: int}
     */
    public static function styleFor(\stdClass $entry, ?\stdClass $evaluation = null): array
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
