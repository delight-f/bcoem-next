<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\CredentialNormalizer;
use App\Support\Brewer\Clubs;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
 * legacy "registration closed" message. CAPTCHA (prefsCAPTCHA) is a
 * client-side widget + server verify in legacy; the port skips the widget
 * and treats prefsCAPTCHA==0 as always-passing (documented deviation —
 * the reCAPTCHA/hCaptcha SDK is out of scope per spec §9 native-replace).
 */
final class RegisterController extends Controller
{
    public function show(Request $request, string $go = 'entrant'): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());

        $registrationOpen = $windows->registration === WindowState::Open;
        $judgeOpen = $windows->judge === WindowState::Open;

        $allowed = match ($go) {
            'judge', 'steward' => $judgeOpen,
            default => $registrationOpen,
        };

        return view('auth.register', [
            'ctx' => $ctx,
            'go' => $go,
            'allowed' => $allowed,
            'registrationOpen' => $registrationOpen,
            'judgeOpen' => $judgeOpen,
            'judgingStarted' => $windows->firstJudgingDate !== null && time() > $windows->firstJudgingDate,
            'futureJudgingSessions' => $windows->futureJudgingSessions,
            'sponsorsVisible' => $ctx->prefsStr('prefsSponsors') === 'Y'
                && (int) DB::table('sponsors')->count() > 0,
            'salutation' => self::t('site.salutation_interest').' '.e($ctx->contestStr('contestName') ?? '')
                .' '.self::t('site.organized_by').' '.e($ctx->contestStr('contestHost') ?? '')
                .(($ctx->contestStr('contestHostLocation') ?? '') !== '' ? ', '.e($ctx->contestStr('contestHostLocation') ?? '') : '').'.',
        ]);
    }

    public function store(Request $request, string $go = 'entrant'): RedirectResponse
    {
        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());

        $allowed = match ($go) {
            'judge', 'steward' => $windows->judge === WindowState::Open,
            default => $windows->registration === WindowState::Open,
        };

        if (! $allowed) {
            return redirect('/?section=register&go='.$go);
        }

        $data = $request->validate([
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
        ]);

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

        // Auto-login (filter=default branch) + rotate CSRF, then redirect.
        $request->session()->regenerate();
        Auth::loginUsingId($userId);
        $request->session()->regenerateToken();

        return redirect('/?section=list&msg=7');
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
