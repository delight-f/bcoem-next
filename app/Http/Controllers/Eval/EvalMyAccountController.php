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
 * injected into the judge's account view.
 *
 * Legacy gating (eval/my_account.eval.php:7) reads the `staff` row via
 * brewer_assignment() — staff_judge == 1 — plus brewerJudge='Y', and only
 * shows the prompt inside the judging window. It does NOT require a
 * judging_assignments row; that is what the /eval dashboard lists.
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

        $staffJudge = (int) DB::table('staff')->where('uid', $uid)->value('staff_judge') === 1;

        $now = time();

        return view('eval.my-account', [
            'ctx' => $ctx,
            'judgeDashboard' => $staffJudge
                && $brewer !== null && $brewer->brewerJudge === 'Y'
                && $now > (int) ($ctx->judgingStr('jPrefsJudgingOpen') ?? 0)
                && $now < (int) ($ctx->judgingStr('jPrefsJudgingClosed') ?? 0),
        ]);
    }
}
