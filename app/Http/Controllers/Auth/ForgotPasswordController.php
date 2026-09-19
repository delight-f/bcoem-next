<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Support\Auth\CredentialNormalizer;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Password reset flow (P3.1c) — port of `pub/login.pub.php` modes +
 * `account_checks.ajax.php` (security-question check + token email) +
 * `process_forgot_password.inc.php` (token verify + reset).
 *
 * Legacy four modes:
 *   forgot       — enter email; AJAX verifies it exists and shows the
 *                  security question (port serves the same form server-side)
 *   verify       — security-question answer checked (phpass-hashed
 *                  userQuestionAnswer); on match: 32-hex token written to
 *                  users.userToken + userTokenTime=time(), reset link emailed
 *   reset-password — token from URL; verify_token() checks 24h expiry
 *                    (4h fallback when userTokenTime missing)
 *   reset POST   — loginUsername + newPassword1/2; both must match token;
 *                  bcrypt hash, clear userToken/Time, redirect ?msg=18
 *
 * Token storage reuses users.userToken/userTokenTime verbatim (D2 — no
 * password_reset_tokens table). Token compare is constant-time
 * (hash_equals), single-use (cleared on successful reset).
 */
final class ForgotPasswordController extends Controller
{
    public function show(Request $request): View
    {
        return view('auth.passwords.forgot', $this->layout($request));
    }

    /**
     * Security-question challenge (GET after forgot-form POST). The question
     * is resolved from the stored account, never the query string (D3-05), so
     * a hand-made URL cannot display attacker-supplied text. An unknown
     * address gets the same neutral fallback a question-less account would.
     */
    public function verifyForm(Request $request): View
    {
        $email = CredentialNormalizer::username($request->string('email', '')->toString());
        $user = $email === ''
            ? null
            : DB::table('users')->where('user_name', $email)->first();

        $stored = $user !== null ? trim((string) $user->userQuestion) : '';

        return view('auth.passwords.verify', $this->layout($request) + [
            'email' => $email,
            'question' => $stored !== '' ? $stored : self::t('site.security_question'),
        ]);
    }

    /** Reset form with token from the emailed link. */
    public function resetForm(Request $request): View|RedirectResponse
    {
        $token = $request->string('token', '')->toString();

        if ($token === '') {
            return redirect()->route('password.forgot');
        }

        return view('auth.passwords.reset', $this->layout($request) + [
            'token' => $token,
            'validity' => $this->tokenValidity($token),
        ]);
    }

    /** @return array<string, mixed> */
    private function layout(Request $request): array
    {
        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);

        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $sponsorsVisible = $ctx->prefsStr('prefsSponsors') === 'Y'
            && (int) DB::table('sponsors')->count() > 0;

        return [
            'ctx' => $ctx,
            'judgingStarted' => $judgingStarted,
            'futureJudgingSessions' => $windows->futureJudgingSessions,
            'sponsorsVisible' => $sponsorsVisible,
            // Section pages render the contest name h1 as the salutation.
            'salutation' => (string) ($ctx->contestStr('contestName') ?? ''),
        ];
    }

    /**
     * Forgot-form POST. Known and unknown addresses land on the same
     * challenge page — the question is resolved server-side there, so this
     * step no longer confirms whether an address exists (D3-02).
     */
    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        return redirect()->route('password.verify', [
            'email' => CredentialNormalizer::username($data['email']),
        ]);
    }

    /** Verify view POST: security answer → token + email + reset link. */
    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'answer' => ['required', 'string'],
        ]);

        $email = CredentialNormalizer::username($data['email']);
        $user = DB::table('users')->where('user_name', $email)->first();

        // An unknown address is answered exactly like a wrong answer, so this
        // step cannot be used to probe which addresses are registered.
        if ($user === null || ! $this->securityAnswerMatches($data['answer'], (string) $user->userQuestionAnswer)) {
            return back()->withErrors(['answer' => self::t('reset.answer_wrong')]);
        }

        // Generate 32-hex token (openssl_random_pseudo_bytes(16) → bin2hex).
        $token = bin2hex(random_bytes(16));

        DB::table('users')->where('id', $user->id)->update([
            'userToken' => $token,
            'userTokenTime' => time(),
        ]);

        // Email the reset link (array/log mailer in tests; SMTP in prod).
        $resetUrl = route('password.reset', ['token' => $token]);
        $brewer = DB::table('brewer')->where('uid', $user->id)->first();
        $name = $brewer
            ? trim($brewer->brewerFirstName.' '.$brewer->brewerLastName)
            : $email;

        Mail::to($email, $name)->send(
            new PasswordResetMail(
                name: $name,
                contestName: (string) (app(TenantContext::class)->contestStr('contestName') ?? ''),
                resetUrl: $resetUrl,
            ),
        );

        return redirect()->route('password.forgot')->with('status', self::t('reset.email_sent'));
    }

    /** Reset POST: newPassword1/2 + loginUsername + token → bcrypt + clear. */
    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'loginUsername' => ['required', 'email'],
            'newPassword1' => ['required', 'string', 'min:8', 'max:72'],
            'newPassword2' => ['required', 'string', 'same:newPassword1'],
        ]);

        $email = CredentialNormalizer::username($data['loginUsername']);

        // Legacy process_forgot_password.inc.php: WHERE user_name AND userToken.
        $user = DB::table('users')
            ->where('user_name', $email)
            ->where('userToken', $data['token'])
            ->first();

        if ($user === null) {
            return redirect()->route('password.reset', ['token' => $data['token']])
                ->withErrors(['token' => self::t('reset.token_invalid')]);
        }

        // verify_token(): 24h expiry (4h fallback if userTokenTime null).
        $validity = $this->tokenValidity($data['token']);
        if ($validity !== 0) {
            return redirect()->route('password.reset', ['token' => $data['token']])
                ->withErrors(['token' => $validity === 2
                    ? self::t('reset.token_expired')
                    : self::t('reset.token_invalid')]);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password' => app('hash')->make($data['newPassword1']),
            'userToken' => null,
            'userTokenTime' => null,
        ]);

        return redirect('/?msg=18');
    }

    /** verify_token(): 0 valid, 1 invalid, 2 expired. */
    private function tokenValidity(string $token): int
    {
        $row = DB::table('users')->where('userToken', $token)->first();

        if ($row === null) {
            return 1;
        }

        $now = time();
        $tokenTime = $row->userTokenTime;
        // 24h from userTokenTime; 4h fallback when the time wasn't recorded.
        $expiredAt = $tokenTime !== null && $tokenTime !== '' && is_numeric($tokenTime)
            ? ((int) $tokenTime + 86400)
            : ($now + 14400);

        return $now <= $expiredAt ? 0 : 2;
    }

    /**
     * Security answers were hashed by phpass HashPassword() — `$2a$` crypt
     * over the RAW answer (unlike passwords, which legacy hashed over
     * md5(plaintext)). Try raw first, then the md5 variant for rows created
     * before the hashing fix.
     */
    private function securityAnswerMatches(string $answer, string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }

        if (password_verify($answer, $storedHash)) {
            return true;
        }

        return str_starts_with($storedHash, '$2a$') && password_verify(md5($answer), $storedHash);
    }

    private static function t(string $key): string
    {
        $value = trans($key);

        return is_string($value) ? $value : (string) $key;
    }
}
