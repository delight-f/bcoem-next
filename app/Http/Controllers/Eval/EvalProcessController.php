<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * process.eval.php port (spec P4.6): insert/update of one judge's
 * `evaluation` row for an entry (add + edit actions).
 *
 * Ownership binding preserved from legacy (:89-106): a non-admin's
 * evaluation is always stored under their own judge identity, and a
 * non-admin may only edit their own row — anything else is 403.
 */
final class EvalProcessController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['evalJudgeInfo'] = $this->judgeId($request);

        DB::table('evaluation')->insert($data);

        return redirect()->route('eval.dashboard', ['msg' => '3']);
    }

    public function update(Request $request, int $evaluationId): RedirectResponse
    {
        $existing = DB::table('evaluation')->where('id', $evaluationId)->first();
        if ($existing === null) {
            abort(404);
        }

        if (! ($request->user()?->isAdmin() ?? false) && $existing->evalJudgeInfo !== (int) $request->user()->id) {
            abort(403);
        }

        DB::table('evaluation')->where('id', $evaluationId)->update($this->validated($request));

        return redirect()->route('eval.dashboard', ['msg' => '2']);
    }

    /**
     * Legacy msg codes: 2 edited, 3 added.
     */
    private function judgeId(Request $request): int
    {
        $user = $request->user();

        // Non-staff judges always sign their own scoresheets.
        return ($user?->isAdmin() ?? false) && is_numeric($request->input('evalJudgeInfo'))
            ? (int) $request->input('evalJudgeInfo')
            : (int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'eid' => ['required', 'integer'],
            'uid' => ['nullable', 'integer'],
            'evalStyle' => ['nullable', 'integer'],
            'evalTable' => ['nullable', 'integer'],
            'evalScoresheet' => ['nullable', 'integer'],
            'evalAromaScore' => ['nullable', 'integer', 'between:0,12'],
            'evalAppearanceScore' => ['nullable', 'integer', 'between:0,24'],
            'evalFlavorScore' => ['nullable', 'integer', 'between:0,50'],
            'evalMouthfeelScore' => ['nullable', 'integer', 'between:0,20'],
            'evalOverallScore' => ['nullable', 'integer', 'between:0,10'],
            'evalFinalScore' => ['required', 'integer', 'between:0,50'],
            'evalStyleAccuracy' => ['nullable', 'integer', 'between:1,5'],
            'evalTechMerit' => ['nullable', 'integer', 'between:1,5'],
            'evalIntangibles' => ['nullable', 'integer', 'between:1,5'],
            'evalDrinkability' => ['nullable', 'string', 'max:255'],
            'evalSpecialIngredients' => ['nullable', 'string'],
            'evalOtherNotes' => ['nullable', 'string'],
            'evalAromaComments' => ['nullable', 'string'],
            'evalAppearanceComments' => ['nullable', 'string'],
            'evalFlavorComments' => ['nullable', 'string'],
            'evalMouthfeelComments' => ['nullable', 'string'],
            'evalOverallComments' => ['nullable', 'string'],
            'evalBottleNotes' => ['nullable', 'string'],
            'evalMiniBOS' => ['nullable', 'integer', 'in:0,1'],
            'evalBottle' => ['nullable', 'integer', 'in:0,1'],
            'evalPosition' => ['nullable', 'string', 'max:15'],
            'evalToken' => ['nullable', 'string', 'max:255'],
            // Structured ticks / descriptors arrive as label arrays and are
            // stored comma-joined in the checklist columns.
            'evalDescriptors' => ['nullable', 'array'],
            'evalDescriptors.*' => ['string'],
            'aromaTicks' => ['nullable', 'array'],
            'aromaTicks.*' => ['string'],
            'appearanceTicks' => ['nullable', 'array'],
            'appearanceTicks.*' => ['string'],
            'flavorTicks' => ['nullable', 'array'],
            'flavorTicks.*' => ['string'],
            'mouthfeelTicks' => ['nullable', 'array'],
            'mouthfeelTicks.*' => ['string'],
            'flaws' => ['nullable', 'array'],
            'flaws.*' => ['string'],
        ]);

        $joined = static fn (string $key): ?string => isset($validated[$key])
            ? implode(', ', array_map(strval(...), $validated[$key]))
            : null;

        $data = [
            'eid' => (int) $validated['eid'],
            'uid' => isset($validated['uid']) ? (int) $validated['uid'] : null,
            'evalStyle' => $validated['evalStyle'] ?? null,
            'evalTable' => $validated['evalTable'] ?? null,
            'evalScoresheet' => $validated['evalScoresheet'] ?? null,
            'evalAromaScore' => $validated['evalAromaScore'] ?? null,
            'evalAppearanceScore' => $validated['evalAppearanceScore'] ?? null,
            'evalFlavorScore' => $validated['evalFlavorScore'] ?? null,
            'evalMouthfeelScore' => $validated['evalMouthfeelScore'] ?? null,
            'evalOverallScore' => $validated['evalOverallScore'] ?? null,
            'evalFinalScore' => (int) $validated['evalFinalScore'],
            'evalStyleAccuracy' => $validated['evalStyleAccuracy'] ?? null,
            'evalTechMerit' => $validated['evalTechMerit'] ?? null,
            'evalIntangibles' => $validated['evalIntangibles'] ?? null,
            'evalDrinkability' => $validated['evalDrinkability'] ?? null,
            'evalSpecialIngredients' => $validated['evalSpecialIngredients'] ?? null,
            'evalOtherNotes' => $validated['evalOtherNotes'] ?? null,
            'evalAromaComments' => $validated['evalAromaComments'] ?? null,
            'evalAppearanceComments' => $validated['evalAppearanceComments'] ?? null,
            'evalFlavorComments' => $validated['evalFlavorComments'] ?? null,
            'evalMouthfeelComments' => $validated['evalMouthfeelComments'] ?? null,
            'evalOverallComments' => $validated['evalOverallComments'] ?? null,
            'evalBottleNotes' => $validated['evalBottleNotes'] ?? null,
            'evalMiniBOS' => (int) ($validated['evalMiniBOS'] ?? 0),
            'evalBottle' => (int) ($validated['evalBottle'] ?? 0),
            'evalPosition' => $validated['evalPosition'] ?? null,
            'evalToken' => $validated['evalToken'] ?? null,
            'evalDescriptors' => $joined('evalDescriptors'),
            'evalAromaChecklist' => $joined('aromaTicks'),
            'evalAppearanceChecklist' => $joined('appearanceTicks'),
            'evalFlavorChecklist' => $joined('flavorTicks'),
            'evalMouthfeelChecklist' => $joined('mouthfeelTicks'),
            'evalFlaws' => $joined('flaws'),
            'evalInitialDate' => time(),
            'evalUpdatedDate' => time(),
        ];

        return $data;
    }
}
