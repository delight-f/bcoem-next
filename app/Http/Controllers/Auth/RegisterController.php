<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationConfirmMail;
use App\Models\User;
use App\Support\Auth\CredentialNormalizer;
use App\Support\Brewer\Clubs;
use App\Support\Security\TurnstileGate;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Coderflex\LaravelTurnstile\Rules\TurnstileCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Public registration (P3.1b) — port of `pub/register.pub.php` +
 * `process_users_register.inc.php` + `process_brewer_info.inc.php`.
 *
 * Legacy shape: a single form at ?section=register&go={entrant|judge|
 * steward}, POST action=add&dbTable=users. On success it writes:
 *   - a `users` row (user_name, bcrypt password, userLevel=2 for public
 *     registration, security Q/A with the answer phpass-hashed)
 *   - a `brewer` row (uid=users.id, contact + demographics + role flags)
 *   - a `staff` row (staff_judge/staff_steward/staff_staff derived from the
 *     role + the brewerJudge/brewerSteward/brewerStaff answers)
 * then auto-logs-in (session loginUsername + CSRF rotate) and redirects to
 * ?section=list&msg=7. Duplicate email → ?section=register&go={go}&msg=2.
 *
 * Window gating: entrant registration requires registration_open == 1;
 * judge/steward requires judge_window_open == 1. Closed states render the
 * legacy "registration closed" message.
 *
 * Bot protection: Cloudflare Turnstile (replacing the legacy reCAPTCHA
 * widget) when enabled via TurnstileGate, plus the honeypot/time-trap
 * middleware on the route and the named `signup` rate limiter. All three
 * are off or invisible on a default install.
 */
final class RegisterController extends Controller
{
    public function show(Request $request, string $go = 'entrant'): View|RedirectResponse
    {
        // Legacy pub/register.pub.php:28-60 — logged-in non-admins
        // (userLevel 2) get bounced; admins (<=1) may register people on
        // their behalf and bypass the window gates.
        $currentUser = Auth::user();
        if ($currentUser !== null && (int) $currentUser->userLevel >= 2) {
            return redirect('/list');
        }
        $adminRegister = $currentUser !== null && (int) $currentUser->userLevel <= 1;

        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());

        // Copy the effective Turnstile keys onto the package config before the
        // widget renders (it reads config('turnstile.turnstile_site_key')).
        TurnstileGate::syncConfig();
        $turnstileEnabled = TurnstileGate::enabled();
        $registrationOpen = $adminRegister || $windows->registration === WindowState::Open;
        $judgeOpen = $adminRegister || $windows->judge === WindowState::Open;

        $allowed = match ($go) {
            'judge', 'steward' => $judgeOpen,
            default => $registrationOpen,
        };

        return view('auth.register', [
            'ctx' => $ctx,
            'go' => $go,
            'allowed' => $allowed,
            'turnstileEnabled' => $turnstileEnabled,
            'adminRegister' => $adminRegister,
            'quickView' => $request->query('view') === 'quick',
            'registrationOpen' => $registrationOpen,
            'judgeOpen' => $judgeOpen,
            'judgingStarted' => $windows->firstJudgingDate !== null && time() > $windows->firstJudgingDate,
            'futureJudgingSessions' => $windows->futureJudgingSessions,
            'sponsorsVisible' => $ctx->prefsStr('prefsSponsors') === 'Y'
                && (int) DB::table('sponsors')->count() > 0,
            // Section pages render the contest name h1 as the salutation.
            'salutation' => (string) ($ctx->contestStr('contestName') ?? ''),
        ]);
    }

    public function store(Request $request, string $go = 'entrant'): RedirectResponse
    {
        // Admins registering on behalf of someone bypass window gates
        $currentUser = Auth::user();
        $adminRegister = $currentUser !== null && (int) $currentUser->userLevel <= 1;

        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());

        if (! $adminRegister) {
            $allowed = match ($go) {
                'judge', 'steward' => $windows->judge === WindowState::Open,
                default => $windows->registration === WindowState::Open,
            };

            if (! $allowed) {
                return redirect('/?section=register&go='.$go);
            }
        }

        $turnstileEnabled = TurnstileGate::enabled();
        TurnstileGate::syncConfig();
        if ($turnstileEnabled && ! TurnstileGate::hasSecret()) {
            // Enabled-but-unconfigured must fail closed and be loud: a silent
            // bypass is exactly the bug class an explicit toggle prevents.
            Log::error('Turnstile is enabled but the secret key is missing; rejecting signup.');
            throw ValidationException::withMessages([
                'cf-turnstile-response' => 'Bot protection is enabled but not configured. Ask the site administrator to set the Turnstile secret key.',
            ]);
        }

        $rules = [
            'user_name' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'userQuestion' => ['required', 'string'],
            'userQuestionAnswer' => ['required', 'string'],
            'brewerFirstName' => ['required', 'string', 'max:200'],
            'brewerLastName' => ['required', 'string', 'max:200'],
            'brewerAddress' => ['nullable', 'string', 'max:255'],
            'brewerCity' => ['nullable', 'string', 'max:255'],
            'brewerState' => ['nullable', 'string', 'max:255'],
            'brewerZip' => ['nullable', 'string', 'max:10'],
            'brewerCountry' => ['nullable', 'string', 'max:255'],
            'brewerPhone1' => ['nullable', 'string', 'max:25'],
            'brewerPhone2' => ['nullable', 'string', 'max:25'],
            'brewerClubs' => ['nullable', 'string'],
            'brewerClubsOther' => ['nullable', 'string'],
            'brewerAHA' => ['nullable', 'string', 'max:255'],
            'brewerMHP' => ['nullable', 'string', 'max:255'],
            'brewerProAm' => ['nullable', 'string'],
            'brewerDropOff' => ['nullable', 'string'],
            'brewerStaff' => ['nullable', 'in:Y,N'],
            'brewerSteward' => ['nullable', 'in:Y,N'],
            'brewerJudge' => ['nullable', 'in:Y,N'],
            'brewerJudgeID' => ['nullable', 'string', 'max:25'],
            'brewerJudgeRank' => ['nullable', 'array'],
            'brewerJudgeMead' => ['nullable', 'in:Y,N'],
            'brewerJudgeCider' => ['nullable', 'in:Y,N'],
            'brewerJudgeLikes' => ['nullable', 'array'],
            'brewerJudgeDislikes' => ['nullable', 'array'],
            'brewerJudgeExp' => ['nullable', 'string', 'max:25'],
            'brewerJudgeNotes' => ['nullable', 'string'],
            'brewerJudgeWaiver' => ['nullable', 'in:Y'],
            'brewerJudgeLocation' => ['nullable', 'string'],
            'brewerStewardLocation' => ['nullable', 'string'],
            'brewerBreweryName' => ['nullable', 'string', 'max:255'],
            'brewerBreweryInfo' => ['nullable', 'string'],
            'brewerAssignment' => ['nullable', 'array'],
            'brewerAssignmentOther' => ['nullable', 'string'],
        ];
        if ($turnstileEnabled) {
            // Present whenever the gate is on, regardless of key validity:
            // "on but broken" must reject, not skip.
            $rules['cf-turnstile-response'] = ['required', new TurnstileCheck];
        }

        try {
            $data = $request->validate($rules);
        } catch (ValidationException $e) {
            if ($turnstileEnabled && $e->validator->errors()->has('cf-turnstile-response')) {
                // Distinct from an ordinary validation failure: points at a
                // config problem or a bot, not a mistyped field.
                Log::error('Turnstile verification failed for a signup attempt.', [
                    'ip' => $request->ip(),
                    'reasons' => $e->validator->errors()->get('cf-turnstile-response'),
                ]);
            }
            throw $e;
        }

        $username = CredentialNormalizer::username($data['user_name']);

        // Failsafe duplicate check (process_users_register.inc.php:132).
        if (str_contains($username, '@') && DB::table('users')->where('user_name', $username)->exists()) {
            return redirect('/?section=register&go='.$go.'&msg=2');
        }

        // Public registration is always userLevel 2; only an authenticated
        // admin session may assign another level (untrusted client value
        // would allow self-registration as admin).
        $userLevel = '2';

        $userId = DB::table('users')->insertGetId([
            'user_name' => $username,
            'userLevel' => $userLevel,
            'password' => app('hash')->make($data['password']),
            'userQuestion' => $data['userQuestion'],
            'userQuestionAnswer' => app('hash')->make($data['userQuestionAnswer']),
            'userCreated' => now()->format('Y-m-d H:i:s'),
            'userAdminObfuscate' => 1,
        ]);

        $brewerJudge = $data['brewerJudge'] ?? 'N';
        $brewerSteward = $data['brewerSteward'] ?? 'N';
        $brewerStaff = $data['brewerStaff'] ?? 'N';

        // Pro-edition entrant registration forces judge/steward off.
        if ((int) $ctx->prefsStr('prefsProEdition') === 1 && $go === 'entrant') {
            $brewerJudge = 'N';
            $brewerSteward = 'N';
        }

        $clubs = $this->clubsValue($data, $ctx);

        DB::table('brewer')->insert([
            'uid' => $userId,
            'brewerFirstName' => $data['brewerFirstName'],
            'brewerLastName' => $data['brewerLastName'],
            'brewerAddress' => $data['brewerAddress'] ?? null,
            'brewerCity' => $data['brewerCity'] ?? null,
            'brewerState' => $data['brewerState'] ?? null,
            'brewerZip' => $data['brewerZip'] ?? null,
            'brewerCountry' => $data['brewerCountry'] ?? null,
            'brewerPhone1' => $data['brewerPhone1'] ?? null,
            'brewerPhone2' => $data['brewerPhone2'] ?? null,
            'brewerClubs' => $clubs ?: null,
            'brewerEmail' => $username,
            'brewerStaff' => $brewerStaff,
            'brewerSteward' => $brewerSteward,
            'brewerJudge' => $brewerJudge,
            'brewerJudgeID' => isset($data['brewerJudgeID']) ? strtoupper($data['brewerJudgeID']) : null,
            'brewerJudgeMead' => $data['brewerJudgeMead'] ?? 'N',
            'brewerJudgeCider' => $data['brewerJudgeCider'] ?? 'N',
            'brewerJudgeRank' => $this->commaJoin($data['brewerJudgeRank'] ?? null),
            'brewerJudgeLikes' => $this->commaJoin($data['brewerJudgeLikes'] ?? null),
            'brewerJudgeDislikes' => $this->commaJoin($data['brewerJudgeDislikes'] ?? null),
            'brewerJudgeLocation' => $data['brewerJudgeLocation'] ?? null,
            'brewerStewardLocation' => $data['brewerStewardLocation'] ?? null,
            'brewerJudgeExp' => $data['brewerJudgeExp'] ?? null,
            'brewerJudgeNotes' => $data['brewerJudgeNotes'] ?? null,
            'brewerJudgeWaiver' => $data['brewerJudgeWaiver'] ?? 'Y',
            'brewerAHA' => $data['brewerAHA'] ?? null,
            'brewerMHP' => $data['brewerMHP'] ?? null,
            'brewerProAm' => $data['brewerProAm'] ?? '0',
            'brewerDropOff' => $data['brewerDropOff'] ?? null,
            'brewerBreweryName' => $data['brewerBreweryName'] ?? null,
            'brewerBreweryInfo' => $data['brewerBreweryInfo'] ?? null,
            'brewerAssignment' => $this->assignmentJson($data),
        ]);

        // Staff row (process_users_register.inc.php:260-330).
        $staffJudge = $go === 'judge' && $brewerJudge === 'Y' ? 1 : 0;
        $staffSteward = $go === 'steward' && $brewerSteward === 'Y' ? 1 : 0;
        $staffStaff = $brewerStaff === 'Y' ? 1 : 0;

        if (DB::table('staff')->where('uid', $userId)->exists()) {
            DB::table('staff')->where('uid', $userId)->update([
                'staff_judge' => $staffJudge,
                'staff_judge_bos' => 0,
                'staff_steward' => $staffSteward,
                'staff_organizer' => 0,
                'staff_staff' => $staffStaff,
            ]);
        } else {
            DB::table('staff')->insert([
                'uid' => $userId,
                'staff_judge' => $staffJudge,
                'staff_judge_bos' => 0,
                'staff_steward' => $staffSteward,
                'staff_organizer' => 0,
                'staff_staff' => $staffStaff,
            ]);
        }

        // Email verification (Task 4): off by default. When on, the new user
        // gets the signed verification link; entry/payment routes are gated
        // by the `verified` middleware (see routes/web.php).
        if ((bool) config('services.email_verification.enabled', false)) {
            User::findOrFail($userId)->sendEmailVerificationNotification();
        }

        // Registration confirmation (P3.6): legacy sent it only when
        // prefsEmailRegConfirm == 1 (and SMTP mode; the port's transport
        // is env-configured). Content ported from
        // process_users_register.inc.php:317-401.
        if ((int) ($ctx->prefsStr('prefsEmailRegConfirm') ?? '0') === 1) {
            Mail::to($username)->send(new RegistrationConfirmMail(
                $data['brewerFirstName'],
                (string) $ctx->contestStr('contestName'),
                $this->confirmRows($data, $clubs, $brewerJudge, $brewerSteward, $brewerStaff),
            ));
        }
        if ($adminRegister) {
            // filter=admin branch (process_users_register.inc.php:430-458):
            // keep the admin session; route to the new participant.
            // ponytail: quick-register judge-info deep link collapses to
            // the participants list until a judge-info edit screen exists.
            return redirect('/backoffice/participants?msg=1');
        }

        // Auto-login (filter=default branch) + rotate CSRF, then redirect.
        $request->session()->regenerate();
        Auth::loginUsingId($userId);
        $request->session()->regenerateToken();

        return redirect('/?section=list&msg=7');
    }

    /**
     * Confirmation-mail table rows, legacy order
     * (process_users_register.inc.php:361-388). Entrant-only fields
     * (club/AHA/MHP/roles/pro-am) appear only for non-brewery contacts.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: string}>
     */
    private function confirmRows(array $data, string $clubs, string $judge, string $steward, string $staff): array
    {
        $yesNo = fn (string $v): string => self::t($v === 'Y' ? 'mail.yes' : 'mail.no');
        $row = fn (string $label, ?string $value): array => ['label' => self::t($label), 'value' => (string) $value];
        $rows = [];

        if ($data['brewerBreweryName'] ?? null) {
            $rows[] = $row('mail.label_brewery', $data['brewerBreweryName']);
        }
        $rows[] = ['label' => self::t('mail.label_name'), 'value' => $data['brewerFirstName'].' '.$data['brewerLastName']];
        $rows[] = ['label' => self::t('mail.label_username'), 'value' => $data['user_name']];
        $rows[] = ['label' => self::t('mail.label_security_question'), 'value' => $data['userQuestion']];

        $address = collect([$data['brewerAddress'] ?? null])->merge([
            implode(', ', array_filter([
                ($data['brewerCity'] ?? '').', '.($data['brewerState'] ?? ''),
                $data['brewerZip'] ?? '',
            ])),
        ])->filter()->implode("\n");
        if ($address !== '') {
            $rows[] = ['label' => self::t('mail.label_address'), 'value' => $address];
        }

        if ($data['brewerPhone1'] ?? null) {
            $rows[] = $row('mail.label_phone_primary', $data['brewerPhone1']);
        }
        if ($data['brewerPhone2'] ?? null) {
            $rows[] = $row('mail.label_phone_secondary', $data['brewerPhone2']);
        }

        if (! isset($data['brewerBreweryName'])) {
            if ($clubs !== '') {
                $rows[] = $row('mail.label_club', $clubs);
            }
            if ($data['brewerAHA'] ?? null) {
                $rows[] = $row('mail.label_aha_number', $data['brewerAHA']);
            }
            if ($data['brewerMHP'] ?? null) {
                $rows[] = $row('mail.label_mhp_number', $data['brewerMHP']);
            }
            $rows[] = $row('mail.label_staff', $yesNo($staff));
            $rows[] = $row('mail.label_judge', $yesNo($judge));
            $rows[] = $row('mail.label_steward', $yesNo($steward));

            $proAm = match ((string) ($data['brewerProAm'] ?? '0')) {
                '1' => self::t('mail.yes'),
                '2' => self::t('mail.opt_out'),
                default => self::t('mail.no'),
            };
            $rows[] = $row('mail.label_pro_am', $proAm);
        }

        return $rows;
    }

    /**
     * Clubs: known club → as-is; "Other" → the Other text; else blank.
     * Semantics live in App\Support\Brewer\Clubs (shared with form 1).
     *
     * @param  array<string, mixed>  $data
     */
    private function clubsValue(array $data, TenantContext $ctx): string
    {
        return Clubs::value($data, $ctx);
    }

    /**
     * @param  list<string>|null  $values
     */
    private function commaJoin(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return implode(',', $values);
    }

    /**
     * brewerAssignment JSON: {"affilliated":[...]} / {"affilliatedOther":[...]}.
     *
     * @param  array<string, mixed>  $data
     */
    private function assignmentJson(array $data): ?string
    {
        $out = [];
        if (! empty($data['brewerAssignment'])) {
            $out['affilliated'] = array_values($data['brewerAssignment']);
        }
        if (! empty($data['brewerAssignmentOther'])) {
            $out['affilliatedOther'] = [ucwords($data['brewerAssignmentOther'])];
        }

        return $out === [] ? null : (string) json_encode($out);
    }

    private static function t(string $key): string
    {
        $value = trans($key);

        return is_string($value) ? $value : (string) $key;
    }
}
