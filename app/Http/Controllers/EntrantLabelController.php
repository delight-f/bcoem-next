<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Output\BottleLabelController;
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
}
