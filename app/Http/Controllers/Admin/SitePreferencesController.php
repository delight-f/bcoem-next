<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Site preferences (spec §7 P5.4) — port of admin/site_preferences.admin.php
 * + process_prefs.inc.php (action=edit). Legacy splits one preferences id=1
 * row across five tabbed sub-forms, each POSTing go=default|entries|email|
 * payment|best with only its own columns; the port mirrors that per-tab
 * write scope exactly.
 *
 * Tab side effects kept from process_prefs.inc.php:
 *  - entries: fee/discount columns live on contest_info (data_entry_fees);
 *    a style-set change rebuilds prefsSelectedStyles from the new set using
 *    the ledger predicates (#5/#6/#7: AABC2025 dual-version + customs,
 *    everything else plain version equality); "by style" limits clear
 *    at-limit flags unless the by-table method is chosen;
 *  - email: turning SMTP off copies the stored host/from/username/... back
 *    over the posted values and forces confirmations/CC off; the stored
 *    password is kept unless change-email-password-choice=1.
 *
 * Documented divergences:
 *  - legacy encrypts SMTP and entry-fee passwords with simpleEncrypt() over
 *    install-secret key material that does not exist in the standalone
 *    build; both are stored as-is (never rendered as HTML anywhere);
 *  - legacy runs BJCP2015→2021→2025 / AABC2022→2025 brewing-row conversion
 *    passes on style-set change; those migration converters are out of scope
 *    (ledger/styles.md — converters are migration paths, not lookups);
 *  - prefsLanguageOptions accepts any string list (legacy intersected with
 *    lang/ directory codes), defaulting to ['en-US'] when empty;
 *  - legacy gated screen+processor to userLevel == 0; port uses the uniform
 *    admin gate.
 */
final class SitePreferencesController extends Controller
{
    private const GO_TABS = ['default', 'entries', 'email', 'payment', 'best'];

    public function edit(Request $request, string $go = 'default'): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        if (! in_array($go, self::GO_TABS, true)) {
            return redirect('/admin/site-preferences');
        }

        return view('admin.site-preferences', [
            'ctx' => TenantContext::load(),
            'go' => $go,
            'styleTypes' => DB::table('style_types')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, string $go = 'default'): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $update = match ($go) {
            'default' => $this->updateDefault($request),
            'entries' => $this->updateEntries($request),
            'email' => $this->updateEmail($request),
            'payment' => $this->updatePayment($request),
            'best' => $this->updateBest($request),
            default => null,
        };

        if ($update === null) {
            return redirect('/admin/site-preferences');
        }

        DB::table('preferences')->where('id', 1)->update($update);

        return redirect('/admin/site-preferences/'.$go.'?msg=2');
    }

    /** @return array<string, mixed> */
    private function updateDefault(Request $request): array
    {
        $tz = TenantContext::load()->prefsStr('prefsTimeZone');
        $data = $request->validate([
            'prefsProEdition' => ['required', 'in:0,1'],
            'prefsMHPDisplay' => ['nullable', 'in:0,1'],
            'prefsDisplayWinners' => ['required', 'in:Y,N'],
            'prefsWinnerDelay' => ['nullable', 'string'],
            'prefsWinnerMethod' => ['required', 'in:0,1,2'],
            'prefsTheme' => ['required', 'string', 'max:50'],
            'prefsSEF' => ['required', 'in:0,1'],
            'prefsUseMods' => ['required', 'in:0,1'],
            'prefsCAPTCHA' => ['nullable', 'in:0,1'],
            'prefsGoogleAccount0' => ['nullable', 'string', 'max:255'],
            'prefsGoogleAccount1' => ['nullable', 'string', 'max:255'],
            'prefsGoogleAccount2' => ['nullable', 'string', 'max:255'],
            'prefsDropOff' => ['required', 'in:0,1,Y,N'],
            'prefsShipping' => ['required', 'in:0,1,Y,N'],
            'prefsAutoPurge' => ['required', 'in:0,1'],
            'prefsLanguage' => ['required', 'string', 'max:10'],
            'prefsLanguageToggle' => ['required', 'in:0,1'],
            'prefsLanguageOptions' => ['nullable', 'array'],
            'prefsDateFormat' => ['required', 'in:0,1,2,999'],
            'prefsTimeFormat' => ['required', 'in:0,1'],
            'prefsTimeZone' => ['required', 'string', 'max:10'],
            'prefsSponsors' => ['required', 'in:Y,N'],
            'prefsSponsorLogos' => ['required', 'in:0,1'],
        ]);
        $data = $this->validateDates($request, $data, ['prefsWinnerDelay'], $tz);

        // Pro edition suppresses the MHP display (legacy quirk).
        $mhp = $data['prefsProEdition'] == 1 ? '0' : (string) ($data['prefsMHPDisplay'] ?? '0');

        // CAPTCHA uses the prefsGoogleAccount column: three pipe-joined parts.
        $google = implode('|', [
            (string) ($data['prefsGoogleAccount0'] ?? ''),
            (string) ($data['prefsGoogleAccount1'] ?? ''),
            (string) ($data['prefsGoogleAccount2'] ?? ''),
        ]);

        $languageOptions = array_values(array_filter(
            is_array($data['prefsLanguageOptions'] ?? null) ? $data['prefsLanguageOptions'] : [],
            fn ($v): bool => is_string($v) && $v !== '',
        ));
        if ($languageOptions === []) {
            $languageOptions = ['en-US'];
        }

        return [
            'prefsProEdition' => (string) $data['prefsProEdition'],
            'prefsMHPDisplay' => $mhp,
            'prefsDisplayWinners' => (string) $data['prefsDisplayWinners'],
            'prefsWinnerDelay' => $this->winnerDelay($data['prefsWinnerDelay'] ?? '', $tz),
            'prefsWinnerMethod' => (string) $data['prefsWinnerMethod'],
            'prefsTheme' => (string) $data['prefsTheme'],
            'prefsSEF' => (string) $data['prefsSEF'],
            'prefsUseMods' => (string) $data['prefsUseMods'],
            'prefsCAPTCHA' => self::blankToNull((string) ($data['prefsCAPTCHA'] ?? '')),
            'prefsGoogleAccount' => self::blankToNull($google),
            // Baseline schema stores these two as tinyint, unlike legacy's
            // char Y/N — normalize on input.
            'prefsDropOff' => in_array($data['prefsDropOff'], ['Y', '1'], true) ? 1 : 0,
            'prefsShipping' => in_array($data['prefsShipping'], ['Y', '1'], true) ? 1 : 0,
            'prefsAutoPurge' => (string) $data['prefsAutoPurge'],
            'prefsLanguage' => (string) $data['prefsLanguage'],
            'prefsLanguageToggle' => (string) $data['prefsLanguageToggle'],
            'prefsLanguageOptions' => json_encode($languageOptions, JSON_THROW_ON_ERROR),
            'prefsDateFormat' => (string) $data['prefsDateFormat'],
            'prefsTimeZone' => (string) $data['prefsTimeZone'],
            'prefsTimeFormat' => (string) $data['prefsTimeFormat'],
            'prefsSponsors' => (string) $data['prefsSponsors'],
            'prefsSponsorLogos' => (string) $data['prefsSponsorLogos'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateEntries(Request $request): array
    {
        $ctx = TenantContext::load();
        $tz = $ctx->prefsStr('prefsTimeZone');
        $data = $request->validate([
            'contestEntryFee' => ['nullable', 'numeric'],
            'contestEntryFee2' => ['nullable', 'numeric'],
            'contestEntryFeeDiscountNum' => ['nullable', 'integer', 'min:1'],
            'contestEntryFeePassword' => ['nullable', 'string', 'max:255'],
            'contestEntryFeePasswordNum' => ['nullable', 'integer', 'min:1'],
            'contestEntryCap' => ['nullable', 'integer', 'min:1'],
            'prefsStyleSet' => ['required', 'string', 'max:20'],
            'prefsEntryForm' => ['required', 'in:0,1'],
            'prefsSpecific' => ['required', 'in:0,1'],
            'prefsSpecialCharLimit' => ['required', 'in:0,1'],
            'prefsEntryLimit' => ['nullable', 'integer', 'min:1'],
            'prefsEntryLimitPaid' => ['nullable', 'integer', 'min:1'],
            'prefsUserEntryLimit' => ['nullable', 'integer', 'min:1'],
            'prefsUserSubCatLimit' => ['nullable', 'integer', 'min:1'],
            'prefsUSCLExLimit' => ['nullable', 'integer', 'min:1'],
            'prefsUSCLEx' => ['nullable', 'array'],
            'choose-style-entry-limits' => ['required', 'in:0,1,2'],
            'user-entry-limit-number-1' => ['nullable', 'integer', 'min:1'],
            'user-entry-limit-expire-days-1' => ['nullable', 'integer', 'min:1'],
            'user-entry-limit-number-2' => ['nullable', 'integer', 'min:1'],
            'user-entry-limit-expire-days-2' => ['nullable', 'integer', 'min:1'],
            'user-entry-limit-number-3' => ['nullable', 'integer', 'min:1'],
            'user-entry-limit-expire-days-3' => ['nullable', 'integer', 'min:1'],
            'user-entry-limit-number-4' => ['nullable', 'integer', 'min:1'],
            'user-entry-limit-expire-days-4' => ['nullable', 'integer', 'min:1'],
        ]);

        // Discount flag derives from BOTH discounted fee and threshold being set.
        $discount = (($data['contestEntryFee2'] ?? '') !== '' && ($data['contestEntryFeeDiscountNum'] ?? '') !== '')
            ? 'Y' : 'N';

        $incremental = [];
        for ($i = 1; $i <= 4; $i++) {
            $number = (string) ($data['user-entry-limit-number-'.$i] ?? '');
            $days = (string) ($data['user-entry-limit-expire-days-'.$i] ?? '');
            if ($number === '' || $days === '') {
                break; // legacy stops at the first empty tier
            }
            $incremental[(string) $i] = ['limit-number' => $number, 'limit-days' => $days];
        }

        $prefs = [
            'prefsStyleSet' => (string) $data['prefsStyleSet'],
            'prefsEntryForm' => (string) $data['prefsEntryForm'],
            'prefsSpecific' => (string) $data['prefsSpecific'],
            'prefsSpecialCharLimit' => (string) $data['prefsSpecialCharLimit'],
            'prefsEntryLimit' => self::blankToNull((string) ($data['prefsEntryLimit'] ?? '')),
            'prefsEntryLimitPaid' => self::blankToNull((string) ($data['prefsEntryLimitPaid'] ?? '')),
            'prefsUserEntryLimit' => self::blankToNull((string) ($data['prefsUserEntryLimit'] ?? '')),
            'prefsUserSubCatLimit' => self::blankToNull((string) ($data['prefsUserSubCatLimit'] ?? '')),
            'prefsUSCLExLimit' => self::blankToNull((string) ($data['prefsUSCLExLimit'] ?? '')),
            'prefsUSCLEx' => self::blankToNull(implode(',', array_filter(
                is_array($data['prefsUSCLEx'] ?? null) ? $data['prefsUSCLEx'] : [],
                fn ($v): bool => is_string($v) && $v !== '',
            ))),
            'prefsUserEntryLimitDates' => $incremental === []
                ? null
                : json_encode($incremental, JSON_THROW_ON_ERROR),
            'prefsStyleLimits' => $this->styleLimits($data, $request),
        ];

        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryFee' => self::blankToNull((string) ($data['contestEntryFee'] ?? '')),
            'contestEntryFee2' => self::blankToNull((string) ($data['contestEntryFee2'] ?? '')),
            'contestEntryFeeDiscount' => $discount,
            'contestEntryFeeDiscountNum' => self::blankToNull((string) ($data['contestEntryFeeDiscountNum'] ?? '')),
            'contestEntryCap' => self::blankToNull((string) ($data['contestEntryCap'] ?? '')),
            'contestEntryFeePassword' => self::blankToNull((string) ($data['contestEntryFeePassword'] ?? '')),
            'contestEntryFeePasswordNum' => self::blankToNull((string) ($data['contestEntryFeePasswordNum'] ?? '')),
        ]);

        // Style-set change → rebuild prefsSelectedStyles from the chosen set
        // (ledger pins #5/#6/#7 predicate shapes).
        $previousSet = $ctx->prefsStr('prefsStyleSet');
        if ((string) $data['prefsStyleSet'] !== (string) $previousSet) {
            $this->rebuildSelectedStyles((string) $data['prefsStyleSet']);
        }

        // Not limiting per-style/table → clear every at-limit flag.
        if ($data['choose-style-entry-limits'] != 1) {
            DB::table('styles')->update(['brewStyleAtLimit' => null]);
        }

        return $prefs;
    }

    /**
     * choose-style-entry-limits semantics: 0 = no per-style limits (""),
     * 1 = by medal group/style (posted <SET>-limit-<group> inputs),
     * 2 = by table ("2" sentinel stored verbatim).
     *
     * @param  array<string, mixed>  $data
     */
    private function styleLimits(array $data, Request $request): ?string
    {
        $method = (string) $data['choose-style-entry-limits'];

        if ($method === '0') {
            return '';
        }

        if ($method === '2') {
            return '2';
        }

        $set = (string) $data['prefsStyleSet'];
        $limits = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($key) && str_contains($key, $set) && str_contains($key, '-limit-') && $value !== '' && $value !== null) {
                $parts = explode('-', $key);
                $limits[$parts[2]] = (string) $value;
            }
        }

        return $limits === [] ? null : json_encode($limits, JSON_THROW_ON_ERROR);
    }

    /** Ledger pins #5/#6/#7: AABC2025 dual-version OR-closure + customs. */
    private function rebuildSelectedStyles(string $set): void
    {
        $query = DB::table('styles');

        if ($set === 'AABC2025') {
            $query->where(function ($q): void {
                $q->where(function ($qq): void {
                    $qq->where('brewStyleVersion', 'AABC2025')->where('brewStyleType', '2');
                })->orWhere(function ($qq): void {
                    $qq->where('brewStyleVersion', 'AABC2022')->where('brewStyleType', '!=', '2');
                })->orWhere('brewStyleOwn', 'custom');
            });
        } else {
            $query->where('brewStyleVersion', $set);
        }

        $selected = [];
        foreach ($query->get(['id', 'brewStyle', 'brewStyleGroup', 'brewStyleNum', 'brewStyleVersion']) as $row) {
            $selected[$row->id] = [
                'id' => $row->id,
                'brewStyle' => $row->brewStyle,
                'brewStyleGroup' => $row->brewStyleGroup,
                'brewStyleNum' => $row->brewStyleNum,
                'brewStyleVersion' => $row->brewStyleVersion,
            ];
        }

        DB::table('preferences')->where('id', 1)->update([
            'prefsSelectedStyles' => json_encode($selected, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string, mixed> */
    private function updateEmail(Request $request): array
    {
        $stored = TenantContext::load()->prefs;
        $data = $request->validate([
            'prefsEmailSMTP' => ['required', 'in:0,1'],
            'prefsContact' => ['required', 'in:Y,N'],
            'prefsEmailRegConfirm' => ['required', 'in:0,1'],
            'change-email-password-choice' => ['required', 'in:0,1'],
            'prefsEmailPassword' => ['nullable', 'string', 'max:255'],
            'prefsEmailFrom' => ['nullable', 'string', 'max:255'],
            'prefsEmailUsername' => ['nullable', 'string', 'max:255'],
            'prefsEmailHost' => ['nullable', 'string', 'max:255'],
            'prefsEmailEncrypt' => ['nullable', 'string', 'max:10'],
            'prefsEmailPort' => ['nullable', 'integer'],
            'prefsEmailCC' => ['nullable', 'in:0,1'],
        ]);

        $from = (string) ($data['prefsEmailFrom'] ?? '');
        $username = trim((string) ($data['prefsEmailUsername'] ?? ''));
        $host = (string) ($data['prefsEmailHost'] ?? '');
        $encrypt = (string) ($data['prefsEmailEncrypt'] ?? '');
        $port = (string) ($data['prefsEmailPort'] ?? '');
        $password = trim((string) ($data['prefsEmailPassword'] ?? ''));
        $confirm = (string) $data['prefsEmailRegConfirm'];
        $cc = (string) ($data['prefsEmailCC'] ?? '0');

        if ($data['change-email-password-choice'] == 1) {
            // Divergence: stored as-is (see class docblock re simpleEncrypt).
        } elseif ((string) ($stored['prefsEmailPassword'] ?? '') !== '') {
            $password = (string) $stored['prefsEmailPassword'];
        }

        if ($data['prefsEmailSMTP'] == 0) {
            // SMTP off: keep the stored transport settings, kill confirmations/CC.
            $from = (string) ($stored['prefsEmailFrom'] ?? '');
            $username = (string) ($stored['prefsEmailUsername'] ?? '');
            $host = (string) ($stored['prefsEmailHost'] ?? '');
            $encrypt = (string) ($stored['prefsEmailEncrypt'] ?? '');
            $port = (string) ($stored['prefsEmailPort'] ?? '');
            $confirm = '0';
            $cc = '0';
        }

        return [
            'prefsEmailSMTP' => (string) $data['prefsEmailSMTP'],
            'prefsEmailFrom' => self::blankToNull($from),
            'prefsEmailUsername' => self::blankToNull($username),
            'prefsEmailPassword' => self::blankToNull($password),
            'prefsEmailHost' => self::blankToNull($host),
            'prefsEmailEncrypt' => self::blankToNull($encrypt),
            'prefsEmailPort' => self::blankToNull($port),
            'prefsContact' => self::blankToNull((string) $data['prefsContact']),
            'prefsEmailRegConfirm' => $confirm,
            'prefsEmailCC' => $cc,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayment(Request $request): array
    {
        $data = $request->validate([
            'prefsCurrency' => ['required', 'string', 'max:5'],
            'prefsPayToPrint' => ['required', 'in:0,1'],
            'prefsCash' => ['required', 'in:0,1'],
            'prefsCheck' => ['required', 'in:0,1'],
            'prefsCheckPayee' => ['nullable', 'string', 'max:255'],
            'prefsPaypal' => ['required', 'in:0,1'],
            'prefsPaypalAccount' => ['nullable', 'string', 'max:255'],
            'prefsPaypalIPN' => ['required', 'in:0,1'],
            'prefsTransFee' => ['required', 'numeric'],
        ]);

        return [
            'prefsCurrency' => (string) $data['prefsCurrency'],
            'prefsPayToPrint' => (string) $data['prefsPayToPrint'],
            'prefsCash' => (string) $data['prefsCash'],
            'prefsCheck' => (string) $data['prefsCheck'],
            'prefsCheckPayee' => self::blankToNull((string) ($data['prefsCheckPayee'] ?? '')),
            'prefsPaypal' => (string) $data['prefsPaypal'],
            'prefsPaypalAccount' => self::blankToNull((string) ($data['prefsPaypalAccount'] ?? '')),
            'prefsPaypalIPN' => (string) $data['prefsPaypalIPN'],
            'prefsTransFee' => (string) $data['prefsTransFee'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateBest(Request $request): array
    {
        $data = $request->validate([
            'prefsShowBestBrewer' => ['required', 'in:0,1'],
            'prefsBestBrewerTitle' => ['nullable', 'string', 'max:100'],
            'prefsShowBestClub' => ['required', 'in:0,1'],
            'prefsBestClubTitle' => ['nullable', 'string', 'max:100'],
            'prefsBestUseBOS' => ['required', 'in:0,1'],
            'prefsScoringCOA' => ['required', 'in:0,1'],
            'prefsFirstPlacePts' => ['required', 'integer', 'min:0'],
            'prefsSecondPlacePts' => ['required', 'integer', 'min:0'],
            'prefsThirdPlacePts' => ['required', 'integer', 'min:0'],
            'prefsFourthPlacePts' => ['required', 'integer', 'min:0'],
            'prefsHMPts' => ['required', 'integer', 'min:0'],
            'prefsTieBreakRule1' => ['nullable', 'integer'],
            'prefsTieBreakRule2' => ['nullable', 'integer'],
            'prefsTieBreakRule3' => ['nullable', 'integer'],
            'prefsTieBreakRule4' => ['nullable', 'integer'],
            'prefsTieBreakRule5' => ['nullable', 'integer'],
            'prefsTieBreakRule6' => ['nullable', 'integer'],
        ]);

        // COA scoring excludes the BOS tie-breaker (legacy quirk).
        $bestUseBOS = $data['prefsScoringCOA'] == 1 ? '0' : (string) $data['prefsBestUseBOS'];

        return [
            'prefsShowBestBrewer' => (string) $data['prefsShowBestBrewer'],
            'prefsBestBrewerTitle' => self::blankToNull((string) ($data['prefsBestBrewerTitle'] ?? '')),
            'prefsShowBestClub' => (string) $data['prefsShowBestClub'],
            'prefsBestClubTitle' => self::blankToNull((string) ($data['prefsBestClubTitle'] ?? '')),
            'prefsBestUseBOS' => $bestUseBOS,
            'prefsScoringCOA' => (string) $data['prefsScoringCOA'],
            'prefsFirstPlacePts' => (string) $data['prefsFirstPlacePts'],
            'prefsSecondPlacePts' => (string) $data['prefsSecondPlacePts'],
            'prefsThirdPlacePts' => (string) $data['prefsThirdPlacePts'],
            'prefsFourthPlacePts' => (string) $data['prefsFourthPlacePts'],
            'prefsHMPts' => (string) $data['prefsHMPts'],
            'prefsTieBreakRule1' => self::blankToNull((string) ($data['prefsTieBreakRule1'] ?? '')),
            'prefsTieBreakRule2' => self::blankToNull((string) ($data['prefsTieBreakRule2'] ?? '')),
            'prefsTieBreakRule3' => self::blankToNull((string) ($data['prefsTieBreakRule3'] ?? '')),
            'prefsTieBreakRule4' => self::blankToNull((string) ($data['prefsTieBreakRule4'] ?? '')),
            'prefsTieBreakRule5' => self::blankToNull((string) ($data['prefsTieBreakRule5'] ?? '')),
            'prefsTieBreakRule6' => self::blankToNull((string) ($data['prefsTieBreakRule6'] ?? '')),
        ];
    }

    /** Empty winner delay stores the legacy far-future sentinel epoch. */
    private function winnerDelay(mixed $value, ?string $tz): int
    {
        if (is_string($value) && trim($value) !== '') {
            $epoch = $this->toUtcEpoch(trim($value), $tz);

            return $epoch ?? 2145916800;
        }

        return 2145916800;
    }

    /**
     * Re-run the date closure against validated data so bad wall-clock strings
     * fail instead of storing garbage epochs.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function validateDates(Request $request, array $data, array $keys, ?string $tz): array
    {
        $request->validate(array_combine($keys, array_map(fn (): array => [
            function (string $attribute, mixed $value, \Closure $fail) use ($tz): void {
                if (is_string($value) && $value !== '' && $this->toUtcEpoch($value, $tz) === null) {
                    $fail("The {$attribute} field is not a valid date/time.");
                }
            },
        ], $keys)));

        return $data;
    }

    private function toUtcEpoch(?string $value, ?string $tzOffset = null): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone(DateFmt::tz($tzOffset)))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
