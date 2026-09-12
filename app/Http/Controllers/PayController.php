<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Payments\CapturableOnReturn;
use App\Support\Payments\FeeCalculator;
use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentProviderRegistry;
use App\Support\Payments\SessionCheckout;
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
        $providers = $this->providerKeys();

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
                // Batch total honors the legacy fee tiers + cap + special
                // brewer rate (payments plan W4, total_fees per-entrant).
                $total = FeeCalculator::forEntrant($ctx, (int) Auth::id(), count($unpaid));

                return view('public.pay', array_merge(app(PublicController::class)->accountData(), [
                    'state' => $this->payable($providers) ? 'payable' : 'unavailable',
                    'firstName' => self::firstName(),
                    'unpaid' => $unpaid,
                    'fee' => $fee,
                    'total' => $total,
                    'providers' => $providers,
                ]));
            }
        }

        return view('public.pay', array_merge(app(PublicController::class)->accountData(), [
            'state' => $state,
            'unpaid' => collect(),
            'fee' => '0',
            'firstName' => self::firstName(),
            'total' => '0.00',
            'providers' => $providers,
        ]));
    }

    /**
     * Starts a checkout for the whole unpaid batch and redirects to the
     * gateway's hosted page. Re-entry safe: nothing owed → back to /pay.
     */
    public function checkout(Request $request): RedirectResponse
    {
        if (! Auth::check()) {
            return redirect('/pay');
        }

        $requested = $request->input('provider');
        $adapter = $this->resolveAdapter(is_string($requested) ? $requested : null);

        if ($adapter === null) {
            // No online gateway, or a crafted/disabled provider: fail safe,
            // nothing charged.
            return $this->payable($this->providerKeys()) ? redirect('/pay?msg=14') : redirect('/pay');
        }

        $ctx = TenantContext::load();
        $ids = array_values(array_map(intval(...), $this->unpaidEntries()->pluck('id')->all()));

        if ($ids === []) {
            return redirect('/pay');
        }

        // Fee snapshot honors the legacy tiers/cap/special-rate model (W4).
        $feeTotal = FeeCalculator::forEntrant($ctx, (int) Auth::id(), count($ids));

        try {
            $checkout = $adapter->createCheckout($ids, (int) Auth::id(), $feeTotal);
        } catch (\Throwable) {
            // Gateway unavailable (misconfiguration/connectivity): fail safe —
            // nothing charged, entrant stays on the pay page.
            return redirect('/pay?msg=14');
        }

        if ($checkout->redirectUrl === null) {
            // No hosted flow (manual marking happens admin-side); nothing to
            // show the entrant here.
            return redirect('/pay');
        }

        return redirect()->away($checkout->redirectUrl);
    }

    /**
     * Gateway success landing (legacy `return` URL carried section=list&
     * msg=13). Confirms the checkout session server-side and renders the
     * legacy paid/cancelled msg codes; flags flip via the signed webhook
     * only. Gateways without hosted sessions have nothing to confirm.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! Auth::check()) {
            return redirect('/pay');
        }

        $requested = $request->query('provider');
        $adapter = $this->resolveAdapter(is_string($requested) ? $requested : null);

        if ($adapter === null) {
            return redirect('/pay');
        }

        // Stripe success return (payments plan W2, review 2c): the gateway's
        // redirect is only a UX hint — the session is confirmed server-side
        // and flags are NOT flipped here; the signed webhook is the single
        // writer (dedup makes overlap harmless).
        if ($adapter instanceof SessionCheckout && (string) $request->query('session_id') !== '') {
            $session = $adapter->retrieveCheckoutSession((string) $request->query('session_id'));

            $paid = $session !== null && ($session->payment_status ?? null) === 'paid';

            return redirect('/pay?msg='.($paid ? '13' : '14'));
        }

        // PayPal return (issue #24 P4): PayPal needs an explicit capture, which
        // is a provider-side side effect only — local flags still flip solely
        // in the signed webhook.
        if ($adapter instanceof CapturableOnReturn && (string) $request->query('token') !== '') {
            $settled = $adapter->captureOnReturn((string) $request->query('token'));

            return redirect('/pay?msg='.($settled ? '13' : '14'));
        }

        return redirect('/pay');
    }

    /**
     * The adapter a request should use: the named provider when the client sent
     * one (resolved through the registry, so a disabled provider is rejected
     * server-side), else the container-bound default. Null means no online
     * gateway is available for this request.
     */
    private function resolveAdapter(?string $provider): ?GatewayAdapter
    {
        if ($provider !== null && $provider !== '') {
            return app(PaymentProviderRegistry::class)->get($provider);
        }

        return app()->bound(GatewayAdapter::class) ? app(GatewayAdapter::class) : null;
    }

    /**
     * Enabled provider keys for the pay UI (issue #24 P3). Empty when this
     * install has no configured provider; the view then renders the legacy
     * single button for the container-bound adapter.
     *
     * @return list<string>
     */
    private function providerKeys(): array
    {
        return array_keys(app(PaymentProviderRegistry::class)->enabled());
    }

    /**
     * @param  list<string>  $providers
     */
    private function payable(array $providers): bool
    {
        return $providers !== [] || app()->bound(GatewayAdapter::class);
    }

    /**
     * Confirmed, unpaid entries in the legacy list order
     * (entries.db.php: brewCategorySort, brewSubCategory).
     *
     * @return Collection<int, \stdClass>
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
