<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Email verification (opt-in, Task 4). Routes are registered always; whether
 * the `verified` gate on the entry/payment routes actually bites is decided
 * per request by EmailVerificationGate — the Site Preferences switch
 * (Email Verification) with EMAIL_VERIFICATION_ENABLED as the install default.
 *
 * The verifiable address is the legacy `users.user_name` column; see
 * User::getEmailForVerification().
 */
final class EmailVerificationController extends Controller
{
    public function notice(): View
    {
        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);

        return view('auth.verify-email', [
            'ctx' => $ctx,
            'judgingStarted' => $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate,
            'futureJudgingSessions' => $windows->futureJudgingSessions,
            'sponsorsVisible' => $ctx->prefsStr('prefsSponsors') === 'Y'
                && (int) DB::table('sponsors')->count() > 0,
            'salutation' => (string) ($ctx->contestStr('contestName') ?? ''),
        ]);
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        // Hash is sha1(email) per Laravel's VerifyEmail notification.
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect('/list');
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect('/login');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect('/list');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
