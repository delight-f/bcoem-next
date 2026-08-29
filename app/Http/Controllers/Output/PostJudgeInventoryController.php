<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\OutputFormat;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Post-judging entry inventory (legacy output/post_judge_inventory.output.php):
 * every entry on record — legacy applies no received filter here — that does
 * NOT yet have a final placement, with the required-info declarations the
 * judge needs to verify (brewInfo plus mead/cider variant checkboxes).
 *
 * go=scores adds the entry's score column (legacy :58-101: scoreEntry
 * rendered with trailing-zero trim when it contains a decimal point).
 */
final class PostJudgeInventoryController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $withScores = $request->query('go') === 'scores';

        // One row per entry in legacy; keyBy mirrors that (last row wins).
        $places = DB::table('judging_scores')->get(['eid', 'scorePlace', 'scoreEntry'])->keyBy('eid');

        $entries = DB::table('brewing')
            ->orderBy('brewCategory')
            ->orderBy('brewSubCategory')
            ->get([
                'id', 'brewJudgingNumber', 'brewName', 'brewCategory',
                'brewCategorySort', 'brewSubCategory', 'brewStyle',
                'brewInfo', 'brewMead1', 'brewMead2', 'brewMead3',
            ]);

        $rows = [];

        foreach ($entries as $entry) {
            $score = $places->get($entry->id);

            // Include when unjudged OR judged without a final place
            // (output_post_judge_inventory.output.php:69).
            $place = $score === null ? null : $score->scorePlace;
            if ($place !== null && $place !== '') {
                continue;
            }

            $info = trim(str_replace('^', ' | ', (string) $entry->brewInfo));
            foreach (['brewMead1', 'brewMead2', 'brewMead3'] as $col) {
                if ($entry->$col !== null && $entry->$col !== '') {
                    $info .= ' *'.$entry->$col.'*';
                }
            }

            // Legacy score render (:95-101): number_format(2) + trailing
            // zero trim only when a decimal point is present.
            $scoreValue = null;
            if ($withScores && $score !== null && $score->scoreEntry !== null && $score->scoreEntry !== '') {
                $raw = (string) $score->scoreEntry;
                $scoreValue = str_contains($raw, '.')
                    ? rtrim(number_format((float) $raw, 2, '.', ''), '0')
                    : $raw;
            }

            $rows[] = [
                'entry' => sprintf('%06s', $entry->id),
                'judging' => OutputFormat::judgingNumber($entry->brewJudgingNumber),
                'name' => (string) $entry->brewName,
                'style' => $baSet
                    ? (string) $entry->brewStyle
                    : trim((string) $entry->brewCategorySort.(string) $entry->brewSubCategory.': '.(string) $entry->brewStyle),
                'info' => $info,
                'score' => $scoreValue,
            ];
        }

        return StreamPdf::response('outputs.post-judge-inventory', [
            'contestName' => $ctx->contestStr('contestName') ?? '',
            'withScores' => $withScores,
            'rows' => $rows,
        ], 'post-judge-inventory.pdf');
    }
}
