<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RememberSignedInSession;
use App\Models\User;
use App\Support\Auth\CredentialNormalizer;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

/**
 * Login/logout against the legacy `users` table (D5: Laravel starter kit
 * adapted; phpass-era sessions not ported).
 *
 * Legacy flow (`includes/logincheck.inc.php`): POST `loginUsername` +
 * `loginPassword`; on success normalize the stored email to lowercase,
 * mirror it into `brewer.brewerEmail`, rotate the CSRF token, and redirect
 * admins (userLevel<=1) to the admin dashboard and everyone else to the
 * entries list. On failure: `?msg=11` (bad credentials) and an
 * `error_log('user authentication failure')` line that fail2ban keys on —
 * there is NO application-level failed-login counter.
 */
final class LoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        // Already logged in? Legacy shows the account surface, not the form.
        if (Auth::check()) {
            /** @var User $user */
            $user = Auth::user();

            return $user !== null && $user->isAdmin()
                ? redirect()->intended('/?section=admin')
                : redirect()->intended('/list');
        }

        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);

        // Showing the inactivity notice consumes the "was signed in" hint so a
        // later guest bounce is not mislabelled as a timeout.
        if ($request->boolean('timeout')) {
            Cookie::queue(Cookie::forget(RememberSignedInSession::COOKIE));
        }

        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $sponsorsVisible = $ctx->prefsStr('prefsSponsors') === 'Y'
            && (int) DB::table('sponsors')->count() > 0;

        return view('auth.login', [
            'ctx' => $ctx,
            'judgingStarted' => $judgingStarted,
            'futureJudgingSessions' => $windows->futureJudgingSessions,
            'sponsorsVisible' => $sponsorsVisible,
            // Section pages (index.pub.php:106-110) render the contest name
            // as the salutation — NOT the landing's thank-you text.
            'salutation' => (string) ($ctx->contestStr('contestName') ?? ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'loginUsername' => ['required', 'string'],
            'loginPassword' => ['required', 'string'],
        ]);

        $username = CredentialNormalizer::username($credentials['loginUsername']);
        $password = (string) $credentials['loginPassword'];

        // Legacy hard cap: passwords longer than 72 bytes destroy the
        // session and bounce to ?msg=11.
        if (strlen($password) > 72) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/?msg=11');
        }

        if (Auth::attempt(['user_name' => $username, 'password' => $password])) {
            $request->session()->regenerate();

            /** @var User $user */
            $user = Auth::user();

            // Legacy mirrors the normalized login email into the brewer row,
            // keyed by the numeric user id (brewer.uid = users.id).
            DB::table('brewer')
                ->where('uid', (int) $user->id)
                ->update(['brewerEmail' => $username]);

            return redirect()->intended($user->isAdmin() ? '/?section=admin' : '/list');
        }

        // Legacy: session destroyed, redirect to ?msg=11, fail2ban hook.
        // The hook is a plain error-log line: Laravel's handler turns any
        // E_USER_WARNING into an uncaught ErrorException in EVERY environment,
        // so trigger_error() here 500'd the request instead of redirecting.
        // error_log() writes the same fail2ban-keyable line to the PHP/Apache
        // error log without raising a warning. Only emitted where a fail2ban
        // jail watches (production).
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Always record the failure (a fail2ban-keyable line); previously this
        // was written only in production, so other environments had no
        // brute-force signal at all.
        error_log('user authentication failure');

        return redirect('/?msg=11');
    }

    public function destroy(Request $request): RedirectResponse
    {
        // The inactivity countdowns log out through /logout?timeout=1 so the
        // login screen can explain why the session ended; a manual logout
        // still lands on the home page with no notice.
        $timedOut = $request->boolean('timeout');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // A deliberate sign-out must not be reported as a timeout later.
        Cookie::queue(Cookie::forget(RememberSignedInSession::COOKIE));

        return $timedOut ? redirect('/login?timeout=1') : redirect('/');
    }
}
