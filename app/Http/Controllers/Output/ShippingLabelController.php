<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Shipping labels (spec §7 P5.2).
 *
 * Legacy: output/shipping_label.output.php via print.output.php
 * section=shipping-label, linked from the brewer's own info page — one
 * label built from $_SESSION brewer fields, rendered twice on a single
 * sheet. The port is the admin batch variant: for every participant who
 * has entries, the same two-up half-sheet pair (one to cut, one spare),
 * addressed to the contest's shipping destination from contest_info.
 */
final class ShippingLabelController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();

        $brewerIds = DB::table('brewing')->distinct()->pluck('brewBrewerID')->map(
            static fn ($id) => (int) $id,
        )->all();

        $brewers = $brewerIds === []
            ? collect()
            : DB::table('brewer')->whereIn('id', $brewerIds)->orderBy('brewerLastName')->get();

        return StreamPdf::response('outputs.shipping-label', [
            'brewers' => $brewers,
            'shippingName' => $ctx->contestStr('contestShippingName'),
            'shippingAddress' => $ctx->contestStr('contestShippingAddress'),
        ], 'shipping-labels.pdf');
    }

    /**
     * Brewer-facing variant (issue #63): the caller's OWN label, addressed to
     * the contest's shipping destination. Legacy linked this from the brewer's
     * own info page and built it from $_SESSION brewer fields; the admin batch
     * route is admin-gated, so that link bounced a logged-in brewer to the
     * /?msg=99 notice with nothing to print. Scoped to the caller's brewer row
     * (mirroring /list/labels), never another brewer's.
     */
    public function own(): Response|RedirectResponse
    {
        $uid = (int) Auth::id();
        if ($uid === 0) {
            return redirect('/?msg=99');
        }

        $brewer = DB::table('brewer')->where('uid', $uid)->first();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();

        return StreamPdf::response('outputs.shipping-label', [
            'brewers' => [$brewer],
            'shippingName' => $ctx->contestStr('contestShippingName'),
            'shippingAddress' => $ctx->contestStr('contestShippingAddress'),
        ], 'shipping-labels.pdf');
    }
}
