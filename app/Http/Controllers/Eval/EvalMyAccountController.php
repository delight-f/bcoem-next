<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * my_account.eval.php port (spec P4.6): the "Judging Dashboard" prompt
 * injected into the judge's account view. Legacy gating (:7-18) kept:
 * assigned as a judge (assignment='J') AND brewerJudge='Y' AND inside
 * the judging window. Legacy rendered this fragment on the brewer
 * account page; here it is a dedicated /eval/my-account surface so the
 * brewer slices stay untouched.
 */
final class EvalMyAccountController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $ctx = TenantContext::load();
        $uid = (int) $user->id;

        $brewer = DB::table('brewer'.EvalDashboardController::archiveSuffix($request))
            ->where('uid', $uid)
            ->first();

        $assigned = DB::table('judging_assignments')
            ->where('bid', $uid)
            ->where('assignment', 'J')
            ->exists();

        $now = time();

        return view('eval.my-account', [
            'ctx' => $ctx,
            'judgeDashboard' => $assigned
                && $brewer !== null && $brewer->brewerJudge === 'Y'
                && $now > (int) ($ctx->judgingStr('jPrefsJudgingOpen') ?? 0)
                && $now < (int) ($ctx->judgingStr('jPrefsJudgingClosed') ?? 0),
        ]);
    }
}
