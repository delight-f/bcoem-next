<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentService;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Public pay page (ticket 15). Ports pub/pay.pub.php behind a login gate:
 * unpaid confirmed entries with the per-entry contestEntryFee and the batch
 * total, a Pay button routed through the active GatewayAdapter
 * (createCheckout → hosted-page redirect), and success/cancel states that
 * render the post-payment flags plus the legacy confirmation texts
 * (alerts.pub.php msg=13 / msg=14).
 *
 * Gating order mirrors pay.pub.php:63-75 — $disable_pay first, then
 * $comp_paid_entry_limit, then the payable surface. The legacy "payments
 * not available" state had no dedicated string: pay.pub.php renders its
 * payment options only when prefsCash/prefsCheck/prefsPaypal == 'Y', so an
 * install with none enabled rendered totals with no way to pay
 * (contest-info ledger #16/#17). The port's equivalent gate is "no
 * GatewayAdapter bound in the container" — manual marking is admin-side
 * (ticket 13), so the public page is payable iff an online adapter is
 * configured.
 */
final class PayController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());

        $state = 'settled';

        if ($windows->disablePay()) {
            $state = 'disabled';
        } elseif ($windows->compPaidEntryLimitReached) {
            $state = 'paid_limit';
        } else {
            $unpaid = $this->unpaidEntries();

            // Free competition (payments ledger #2): entries are marked paid
            // at creation when contestEntryFee == 0, so nothing is ever owed
            // here. Same surface covers the already-paid re-entry.
            if ($unpaid->isNotEmpty() && (float) self::feePerEntry() !== 0.0) {
                $fee = self::feePerEntry();
                $total = bcmul((string) count($unpaid), $fee);

                return view('public.pay', array_merge(app(PublicController::class)->accountData(), [
                    'state' => app()->bound(GatewayAdapter::class) ? 'payable' : 'unavailable',
                    'firstName' => self::firstName(),
                    'unpaid' => $unpaid,
                    'fee' => $fee,
                    'total' => $total,
                ]));
            }
        }

        return view('public.pay', array_merge(app(PublicController::class)->accountData(), [
            'state' => $state,
            'unpaid' => collect(),
            'fee' => '0',
            'firstName' => self::firstName(),
            'total' => '0.00',
        ]));
    }

    /**
     * Starts a checkout for the whole unpaid batch and redirects to the
     * gateway's hosted page. Re-entry safe: nothing owed → back to /pay.
     */
    public function checkout(): RedirectResponse
    {
        if (! Auth::check() || ! app()->bound(GatewayAdapter::class)) {
            return redirect('/pay');
        }

        $ids = array_values(array_map(intval(...), $this->unpaidEntries()->pluck('id')->all()));

        if ($ids === []) {
            return redirect('/pay');
        }

        $feeTotal = number_format((float) bcmul((string) count($ids), self::feePerEntry()), 2, '.', '');

        $checkout = app(GatewayAdapter::class)->createCheckout($ids, (int) Auth::id(), $feeTotal);

        if ($checkout->redirectUrl === null) {
            // No hosted flow (manual marking happens admin-side); nothing to
            // show the entrant here.
            return redirect('/pay');
        }

        return redirect()->away($checkout->redirectUrl);
    }

    /**
     * Gateway success landing (legacy `return` URL carried section=list&
     * msg=13). The payload mirrors the legacy PayPal custom field shape —
     * entrant uid plus the dashed entry-id list — plus whatever query
     * params the gateway appends. Verified Paid results flip flags through
     * PaymentService; anything else lands on the cancelled state.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! Auth::check() || ! app()->bound(GatewayAdapter::class)) {
            return redirect('/pay');
        }

        $adapter = app(GatewayAdapter::class);

        // Success return (payments plan W2): the gateway's redirect is only
        // a UX hint. Confirm server-side — for Stripe, the {CHECKOUT_SESSION_ID}
        // template in success_url resolves to a real session id we retrieve
        // from the connected account. Flags are NOT flipped here: the signed
        // webhook is the single writer (dedup makes overlap harmless).
        if ((string) $request->query('session_id') !== '' && method_exists($adapter, 'retrieveCheckoutSession')) {
            $session = $adapter->retrieveCheckoutSession((string) $request->query('session_id'));

            $paid = $session !== null && ($session->payment_status ?? null) === 'paid';

            return redirect('/pay?msg='.($paid ? '13' : '14'));
        }

        // Other transports (none today): the legacy-shaped callback payload.
        $result = $adapter->handleCallback($request->query->all());

        if (! $result->isPaid()) {
            return redirect('/pay?msg=14');
        }

        // Only this entrant's own currently-unpaid entries, intersected with
        // what the callback claims — a tampered entry list cannot pay
        // someone else's batch or already-settled entries.
        $claimed = array_values(array_filter(
            explode('-', $request->query->getString('entries', '')),
            fn (string $v): bool => ctype_digit($v),
        ));
        $batch = array_values(array_intersect(
            array_map(intval(...), $claimed),
            array_values(array_map(intval(...), $this->unpaidEntries()->pluck('id')->all())),
        ));

        if ($batch !== []) {
            app(PaymentService::class)->apply(
                $result,
                $batch,
                (int) Auth::id(),
                $adapter->method(),
                $result->amount ?? number_format((float) bcmul((string) count($batch), self::feePerEntry()), 2, '.', ''),
            );
        }

        return redirect('/pay?msg=13');
    }

    /**
     * Confirmed, unpaid entries in the legacy list order
     * (entries.db.php: brewCategorySort, brewSubCategory).
     *
     * @return Collection<int, object>
     */
    private function unpaidEntries()
    {
        return DB::table('brewing')
            ->where('brewBrewerID', (int) Auth::id())
            ->where('brewConfirmed', '1')
            ->where('brewPaid', '!=', 1)
            ->orderBy('brewCategorySort')
            ->orderBy('brewSubCategory')
            ->get(['id', 'brewName', 'brewCategory', 'brewSubCategory', 'brewStyle']);
    }

    private static function firstName(): string
    {
        return (string) (DB::table('brewer')->where('uid', (int) Auth::id())->value('brewerFirstName') ?? '');
    }

    /** @return numeric-string */
    private static function feePerEntry(): string
    {
        $fee = TenantContext::load()->contestStr('contestEntryFee') ?? '0';

        return is_numeric($fee) ? $fee : '0';
    }
}
