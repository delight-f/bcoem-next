<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $existing = DB::table('evaluation')->where('id', $evaluationId)->first();
        if ($existing === null) {
            abort(404);
        }

        if (! $user->isAdmin() && $existing->evalJudgeInfo !== (int) $user->id) {
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
        if (! $user instanceof User) {
            abort(403);
        }

        // Non-staff judges always sign their own scoresheets.
        return $user->isAdmin() && is_numeric($request->input('evalJudgeInfo'))
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
            'evalFlaws' => ['nullable', 'array'],
            'evalFlaws.*' => ['string'],

            // Checklist sheet (jPrefsScoresheet=2) factor radios — "Label: Level".
            'evalAromaMalt' => ['nullable', 'string', 'max:255'],
            'evalAromaHops' => ['nullable', 'string', 'max:255'],
            'evalAromaEsters' => ['nullable', 'string', 'max:255'],
            'evalAromaPhenols' => ['nullable', 'string', 'max:255'],
            'evalAromaAlcohol' => ['nullable', 'string', 'max:255'],
            'evalAromaSweetness' => ['nullable', 'string', 'max:255'],
            'evalAromaAcidity' => ['nullable', 'string', 'max:255'],
            'evalAppearanceHeadSize' => ['nullable', 'string', 'max:255'],
            'evalAppearanceHeadRetention' => ['nullable', 'string', 'max:255'],
            'evalFlavorMalt' => ['nullable', 'string', 'max:255'],
            'evalFlavorHops' => ['nullable', 'string', 'max:255'],
            'evalFlavorEsters' => ['nullable', 'string', 'max:255'],
            'evalFlavorPhenols' => ['nullable', 'string', 'max:255'],
            'evalFlavorSweetness' => ['nullable', 'string', 'max:255'],
            'evalFlavorBitterness' => ['nullable', 'string', 'max:255'],
            'evalFlavorAlcohol' => ['nullable', 'string', 'max:255'],
            'evalFlavorAcidity' => ['nullable', 'string', 'max:255'],
            'evalFlavorHarshness' => ['nullable', 'string', 'max:255'],
            'evalMouthfeelCarbonation' => ['nullable', 'string', 'max:255'],
            'evalMouthfeelWarmth' => ['nullable', 'string', 'max:255'],
            'evalMouthfeelCreaminess' => ['nullable', 'string', 'max:255'],
            'evalMouthfeelAstringency' => ['nullable', 'string', 'max:255'],
            'evalAromaChecklistDesc' => ['nullable', 'array'],
            'evalAromaChecklistDesc.*' => ['string'],
            'evalAppearanceChecklistDesc' => ['nullable', 'array'],
            'evalAppearanceChecklistDesc.*' => ['string'],
            'evalFlavorChecklistDesc' => ['nullable', 'array'],
            'evalFlavorChecklistDesc.*' => ['string'],
            'evalMouthfeelChecklistDesc' => ['nullable', 'array'],
            'evalMouthfeelChecklistDesc.*' => ['string'],

            // NW Cider structured sheet (jPrefsScoresheet=4) — stored as JSON.
            'evalAppearanceColorChoice' => ['nullable', 'string', 'max:50'],
            'evalAppearanceColorOther' => ['nullable', 'string', 'max:50'],
            'evalAppearanceColorInappr' => ['nullable', 'in:1'],
            'evalAppearanceClarity' => ['nullable', 'string', 'max:10'],
            'evalAppearanceClarityInappr' => ['nullable', 'in:1'],
            'evalAppearanceCarb' => ['nullable', 'string', 'max:10'],
            'evalAppearanceCarbInappr' => ['nullable', 'in:1'],
            'evalAromaCharacteristics' => ['nullable', 'string'],
            'evalAromaIntensity' => ['nullable', 'string', 'max:10'],
            'evalAromaIntensityInappr' => ['nullable', 'in:1'],
            'evalAromaQuality' => ['nullable', 'string', 'max:10'],
            'evalAromaQualityInappr' => ['nullable', 'in:1'],
            'evalFlavorCharacteristics' => ['nullable', 'string'],
            'evalFlavorIntensity' => ['nullable', 'string', 'max:10'],
            'evalFlavorIntensityInappr' => ['nullable', 'in:1'],
            'evalFlavorQuality' => ['nullable', 'string', 'max:10'],
            'evalFlavorQualityInappr' => ['nullable', 'in:1'],
            'evalMouthfeelBodyInappr' => ['nullable', 'in:1'],
            'evalMouthfeelSweetness' => ['nullable', 'string', 'max:10'],
            'evalMouthfeelSweetnessInappr' => ['nullable', 'in:1'],
            'evalMouthfeelAcidity' => ['nullable', 'string', 'max:10'],
            'evalMouthfeelAcidityInappr' => ['nullable', 'in:1'],
            'evalMouthfeelTanninBitter' => ['nullable', 'string', 'max:10'],
            'evalMouthfeelTanninBitterInappr' => ['nullable', 'in:1'],
            'evalMouthfeelTanninAstringency' => ['nullable', 'string', 'max:10'],
            'evalMouthfeelTanninAstringencyInappr' => ['nullable', 'in:1'],
            'evalMouthfeelBalance' => ['nullable', 'string', 'max:10'],
            'evalMouthfeelBalanceInappr' => ['nullable', 'in:1'],
            'evalMouthfeelLength' => ['nullable', 'string', 'max:10'],
            'evalMouthfeelLengthInappr' => ['nullable', 'in:1'],
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
            'evalFlaws' => $joined('flaws') ?? $joined('evalFlaws'),
            'evalInitialDate' => time(),
            'evalUpdatedDate' => time(),
        ];

        // The checklist sheet stores its factor selections joined in the same
        // columns the structured sheet uses for ticks; the NW Cider sheet
        // stores a per-section JSON object (legacy process.eval.php).
        $variant = (int) ($data['evalScoresheet'] ?? 0);
        if ($variant === 2) {
            $data = array_merge($data, $this->checklistColumns($validated));
        } elseif ($variant === 4) {
            $data = array_merge($data, $this->nwCiderColumns($validated));
        }

        return $data;
    }

    /** Checklist factor radios per column, in the legacy assembly order. */
    private const CHECKLIST_FACTORS = [
        'evalAromaChecklist' => ['evalAromaMalt', 'evalAromaHops', 'evalAromaEsters', 'evalAromaPhenols', 'evalAromaAlcohol', 'evalAromaSweetness', 'evalAromaAcidity'],
        'evalAppearanceChecklist' => ['evalAppearanceClarity', 'evalAppearanceHeadSize', 'evalAppearanceHeadRetention'],
        'evalFlavorChecklist' => ['evalFlavorMalt', 'evalFlavorHops', 'evalFlavorEsters', 'evalFlavorPhenols', 'evalFlavorSweetness', 'evalFlavorBitterness', 'evalFlavorAlcohol', 'evalFlavorAcidity', 'evalFlavorHarshness'],
        'evalMouthfeelChecklist' => ['evalMouthfeelBody', 'evalMouthfeelCarbonation', 'evalMouthfeelWarmth', 'evalMouthfeelCreaminess', 'evalMouthfeelAstringency'],
    ];

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    private function checklistColumns(array $validated): array
    {
        $out = [];
        foreach (self::CHECKLIST_FACTORS as $column => $fields) {
            $parts = [];
            foreach ($fields as $field) {
                $value = $validated[$field] ?? null;
                if (is_string($value) && $value !== '') {
                    $parts[] = $value;
                }
            }
            $out[$column] = $parts === [] ? null : implode(', ', $parts);
        }

        foreach (['evalAromaChecklistDesc', 'evalAppearanceChecklistDesc', 'evalFlavorChecklistDesc', 'evalMouthfeelChecklistDesc'] as $column) {
            $items = $validated[$column] ?? null;
            $out[$column] = is_array($items) && $items !== []
                ? implode(', ', array_map(strval(...), $items))
                : null;
        }

        return $out;
    }

    /** NW Cider fields grouped per section, matching the JSON keys legacy reads. */
    private const NW_CIDER_SECTIONS = [
        'evalAppearanceChecklist' => ['evalAppearanceColor', 'evalAppearanceColorInappr', 'evalAppearanceClarity', 'evalAppearanceClarityInappr', 'evalAppearanceCarb', 'evalAppearanceCarbInappr'],
        'evalAromaChecklist' => ['evalAromaCharacteristics', 'evalAromaIntensity', 'evalAromaIntensityInappr', 'evalAromaQuality', 'evalAromaQualityInappr'],
        'evalFlavorChecklist' => ['evalFlavorCharacteristics', 'evalFlavorIntensity', 'evalFlavorIntensityInappr', 'evalFlavorQuality', 'evalFlavorQualityInappr'],
        'evalMouthfeelChecklist' => ['evalMouthfeelBody', 'evalMouthfeelBodyInappr', 'evalMouthfeelSweetness', 'evalMouthfeelSweetnessInappr', 'evalMouthfeelAcidity', 'evalMouthfeelAcidityInappr', 'evalMouthfeelTanninBitter', 'evalMouthfeelTanninBitterInappr', 'evalMouthfeelTanninAstringency', 'evalMouthfeelTanninAstringencyInappr', 'evalMouthfeelBalance', 'evalMouthfeelBalanceInappr', 'evalMouthfeelLength', 'evalMouthfeelLengthInappr'],
    ];

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    private function nwCiderColumns(array $validated): array
    {
        // legacy process.eval.php: convert the colour radio to the stored
        // evalAppearanceColor key, resolving the "Other" text choice.
        $choice = $validated['evalAppearanceColorChoice'] ?? null;
        $validated['evalAppearanceColor'] = match (true) {
            $choice === '999' => $validated['evalAppearanceColorOther'] ?? null,
            is_string($choice) && $choice !== '' => $choice,
            default => null,
        };

        $out = [];
        foreach (self::NW_CIDER_SECTIONS as $column => $fields) {
            $section = [];
            foreach ($fields as $field) {
                $value = $validated[$field] ?? null;
                if ($value !== null && $value !== '') {
                    $section[$field] = $value;
                }
            }
            $out[$column] = $section === [] ? null : (string) json_encode($section);
        }

        return $out;
    }
}
