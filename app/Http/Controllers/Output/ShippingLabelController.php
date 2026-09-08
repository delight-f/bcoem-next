<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
}
