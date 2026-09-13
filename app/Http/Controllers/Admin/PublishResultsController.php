<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Publish Results (legacy process.inc.php $action="publish", lines 348-410).
 *
 * Publishing makes winners public and closes the competition: sets
 * prefsDisplayWinners=Y + prefsWinnerDelay=now, forces every future
 * deadline (registration, entry, judge, judging-closed) to now, snaps each
 * judging location's future judgingDate to now and every judgingLocType=1
 * location's judgingDateEnd to now, then drops the cached prefs session
 * blob and lands on /admin?msg=36 ("Results are published.").
 *
 * Admin-only (userLevel 0) — same gate as every legacy process action.
 */
final class PublishResultsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        if ($actor === null || (int) $actor->userLevel !== 0) {
            return redirect('/?msg=99');
        }

        $now = time();

        DB::table('preferences')->where('id', 1)->update([
            'prefsDisplayWinners' => 'Y',
            'prefsWinnerDelay' => $now,
        ]);

        $ctx = TenantContext::load();

        foreach ([
            'contestRegistrationDeadline',
            'contestEntryDeadline',
            'contestJudgeDeadline',
        ] as $column) {
            if (($ctx->contestEpoch($column) ?? 0) > $now) {
                DB::table('contest_info')->where('id', 1)->update([$column => $now]);
            }
        }

        $judgingClosed = DB::table('judging_preferences')->where('id', 1)->value('jPrefsJudgingClosed');
        if ((int) ($judgingClosed ?? 0) > $now) {
            DB::table('judging_preferences')->where('id', 1)->update(['jPrefsJudgingClosed' => $now]);
        }

        DB::table('judging_locations')
            ->where('judgingDate', '>', $now)
            ->update(['judgingDate' => $now]);

        DB::table('judging_locations')
            ->where('judgingLocType', 1)
            ->update(['judgingDateEnd' => $now]);

        // Legacy unsets $_SESSION['prefs'.$prefix_session]; the port's
        // TenantContext re-reads per request, nothing cached to drop.

        return redirect('/admin?msg=36');
    }
}
