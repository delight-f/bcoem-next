<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Output\BottleLabelController;
use App\Http\Controllers\Output\LabelsController;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Entrant-facing entry bottle/can labels (Payment tab "Pay to Print?").
 *
 * Legacy let a logged-in brewer print their OWN labels at
 * output/bottle_label.output.php behind a bid ownership check; the port
 * originally dropped that surface (the /admin/output group is admin-gated).
 * This restores the entrant route with the ownership check the port omitted:
 * the entry set is resolved from the session user only — never a
 * client-supplied bid — and when prefsPayToPrint is on, unpaid entries are
 * refused with a clear message instead of silently rendering.
 */
final class EntrantLabelController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $uid = (int) Auth::id();
        if ($uid === 0) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        // The entrant's own entries only.
        $ownIds = DB::table('brewing')->where('brewBrewerID', $uid)->pluck('id')->all();

        // Optional ?ids= subset, intersected with the caller's own entries.
        $idsQuery = $request->query('ids', '');
        $requested = is_string($idsQuery) && $idsQuery !== ''
            ? array_values(array_intersect(array_filter(array_map('intval', explode(',', $idsQuery))), $ownIds))
            : $ownIds;

        if ($requested === []) {
            return redirect('/list');
        }

        // Pay to Print: no labels while anything in the batch is unpaid.
        if ($ctx->prefsStr('prefsPayToPrint') === '1') {
            $unpaid = DB::table('brewing')->whereIn('id', $requested)->where('brewPaid', '!=', 1)->exists();
            if ($unpaid) {
                return redirect('/list?msg=13');
            }
        }

        $data = BottleLabelController::build($ctx, implode(',', $requested), 'default', (string) $uid);

        return StreamPdf::response('outputs.bottle_label', $data['view'], $data['filename']);
    }

    /**
     * Judge-facing scoresheet labels (D2-03): the account page links here so a
     * judge can print their OWN labels — the admin outputs.labels route is
     * admin-gated. LabelsController::judgingLabels() builds every judge's
     * block; only the caller's own block (matched by their stored email) is
     * emitted, so no other judge's details leave the server.
     */
    public function scoresheet(Request $request): Response|RedirectResponse
    {
        $uid = (int) Auth::id();
        if ($uid === 0) {
            return redirect('/?msg=99');
        }

        $email = strtolower((string) (DB::table('brewer')->where('uid', $uid)->value('brewerEmail') ?? ''));
        if ($email === '') {
            return redirect('/list');
        }

        $psort = $request->query('psort') === '3422' ? '3422' : '5160';

        $sheet = LabelsController::judgingLabels(TenantContext::load(), $psort);
        /** @var list<list<string>> $all */
        $all = $sheet['view']['labels'];
        $labels = array_values(array_filter(
            $all,
            static fn (array $lines): bool => strtolower((string) end($lines)) === $email,
        ));

        if ($labels === []) {
            return redirect('/list');
        }

        $contest = str_replace(' ', '_', (string) TenantContext::load()->contestStr('contestName'));

        return StreamPdf::response('outputs.labels', [
            'perSheet' => $psort === '3422' ? 24 : 30,
            'labels' => $labels,
        ], $contest.'_Judge_Scoresheet_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf');
    }
}
