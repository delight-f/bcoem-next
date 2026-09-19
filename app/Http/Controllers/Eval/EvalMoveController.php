<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Repoint a finished evaluation at the entry it actually belongs to
 * (issue #1756): judges routinely complete a scoresheet against the wrong
 * entry, and the old remedy was copying the text out by hand.
 *
 * Only the entry-derived columns move (eid/uid/evalStyle/evalTable) — the
 * judges' scores and comments travel untouched, because they describe the
 * beer that was actually judged. The official score rows (judging_scores)
 * are derived from the evaluations, so both affected entries have theirs
 * cleared and the admin rebuilds them with the consensus import
 * (EvalConsensus / "Import Score Data"). The move never imports by itself:
 * it must not make other entries' evaluations official as a side effect.
 *
 * Admin gating is in-controller (userLevel<=1), like every eval surface.
 */
final class EvalMoveController extends Controller
{
    public function move(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = $request->validate([
            'evaluationId' => ['required', 'integer'],
            'target' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'in:yes'],
        ]);

        $evaluationId = (int) $data['evaluationId'];
        $evaluation = DB::table('evaluation')->where('id', $evaluationId)->first();

        if ($evaluation === null) {
            return back()->withErrors(['target' => 'That evaluation no longer exists.']);
        }

        $archive = EvalDashboardController::archiveSuffix($request);
        $target = trim((string) $data['target']);

        // Admin-supplied identifier: an entry id wins over a judging number.
        $destination = ctype_digit($target)
            ? DB::table('brewing'.$archive)->where('id', (int) $target)->first()
            : null;

        $destination ??= DB::table('brewing'.$archive)->where('brewJudgingNumber', $target)->first();

        if ($destination === null) {
            return back()->withErrors(['target' => 'No entry matches "'.$target.'".']);
        }

        $oldEid = (int) $evaluation->eid;
        $newEid = (int) $destination->id;

        if ($oldEid === $newEid) {
            return back()->withErrors(['target' => 'That evaluation is already on this entry.']);
        }

        $update = [
            'eid' => $newEid,
            // The scoresheet posts exactly this as its hidden `uid`.
            'uid' => $destination->brewBrewerID,
            // Keeps the scoresheet variant and the consensus scoreType in
            // step with the entry the evaluation lands on.
            'evalStyle' => self::styleId($destination) ?? $evaluation->evalStyle,
            'evalUpdatedDate' => time(),
        ];

        $tableId = self::tableId($newEid);
        if ($tableId !== null) {
            $update['evalTable'] = $tableId;
        }

        DB::transaction(function () use ($evaluationId, $update, $oldEid, $newEid): void {
            DB::table('evaluation')->where('id', $evaluationId)->update($update);
            DB::table('judging_scores')->whereIn('eid', [$oldEid, $newEid])->delete();
        });

        return redirect()
            ->route('eval.output', ['entryId' => $newEid, 'archive' => $archive ?: null])
            ->with('status', sprintf(
                'Evaluation #%d moved to entry %d (%s). Official score rows for entries %d and %d were cleared — run "Import Score Data" to rebuild them.',
                $evaluationId,
                $newEid,
                (string) $destination->brewName,
                $oldEid,
                $newEid,
            ));
    }

    /** Style id for an entry, by the same rule the scoresheet resolves it. */
    private static function styleId(\stdClass $entry): ?int
    {
        $style = EvalScoresheetController::styleFor($entry)[0];

        return $style === null ? null : (int) $style->id;
    }

    /**
     * The judging table the entry is flighted to, or null when it is not
     * flighted: flightEntryID holds the brewing.id (legacy wrote CSV lists,
     * the port's FlightAssignment one id per row).
     */
    private static function tableId(int $entryId): ?int
    {
        foreach (DB::table('judging_flights')->orderBy('flightRound')->orderBy('id')->get(['flightTable', 'flightEntryID']) as $flight) {
            foreach (explode(',', (string) $flight->flightEntryID) as $id) {
                if (is_numeric(trim($id)) && (int) trim($id) === $entryId) {
                    return (int) $flight->flightTable;
                }
            }
        }

        return null;
    }
}
