<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Mail\MailSettings;
use App\Support\Styles\StyleSets;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
 *    StyleSets::activeQuery() (#5/#6/#7: dual-version sets span the
 *    predecessor version, customs extend every set); "by style" limits clear
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

    /** Legacy constants.inc.php $languages. */
    private const LANGUAGES = [
        'pt-BR' => 'Brazilian Portuguese',
        'cs-CZ' => 'Czech',
        'en-GB' => 'English (GB)',
        'en-US' => 'English (US)',
        'fr-FR' => 'French',
        'hu-HU' => 'Hungarian',
        'es-419' => 'Spanish (Latin America)',
    ];

    /** Legacy site_preferences.admin.php:1213-1258 time zone select options. */
    private const TIMEZONES = [
        '-12.000' => '(GMT -12:00) International Date Line West, Eniwetok, Kwajalein, Baker Island, Howland Island',
        '-11.000' => '(GMT -11:00) Midway Island, Samoa, Pago Pago',
        '-10.000' => '(GMT -10:00) Hawaii',
        '-9.000' => '(GMT -9:00) Alaska',
        '-9.500' => '(GMT -9:30) Marquesas',
        '-8.000' => '(GMT -8:00) Pacific Time (US &amp; Canada), Tiajuana',
        '-7.000' => '(GMT -7:00) Mountain Time (US &amp; Canada)',
        '-7.001' => '(GMT -7:00) Mountain Time - Arizona (No Daylight Savings)',
        '-6.000' => '(GMT -6:00) Central Time (US &amp; Canada), Central America',
        '-6.001' => '(GMT -6:00) Sonora, Mexico (No Daylight Savings)',
        '-6.002' => '(GMT -6:00) Canada Central Time (No Daylight Savings)',
        '-5.000' => '(GMT -5:00) Eastern Time (US &amp; Canada)',
        '-5.001' => '(GMT -5:00) Bogota, Lima (No Daylight Savings)',
        '-4.000' => '(GMT -4:00) Caracas, La Paz, Virgin Islands (No Daylight Savings)',
        '-4.001' => '(GMT -4:00) Paraguay (No Daylight Savings)',
        '-4.002' => '(GMT -4:00) Atlantic Time (Canada)',
        '-4.003' => '(GMT -4:00) Santiago, Chile',
        '-4.004' => '(GMT -4:00) Thule, Greenland',
        '-3.500' => '(GMT -3:30) Newfoundland',
        '-3.000' => '(GMT -3:00) Buenos Aires, Georgetown, Greenland',
        '-3.001' => '(GMT -3:00) Brazil (Brasilia - No Daylight Savings)',
        '-2.000' => '(GMT -2:00) Mid-Atlantic',
        '-1.000' => '(GMT -1:00 hour) Azores, Cape Verde Islands, Ittoqqortoormiit',
        '0.000' => '(GMT) Western Europe Time, London, Lisbon, Casablanca, Monrovia',
        '1.000' => '(GMT +1:00 hour) Brussels, Copenhagen, Madrid, Paris, Lagos',
        '2.000' => '(GMT +2:00) Kaliningrad, Johannesburg, Cairo Helsinki',
        '3.000' => '(GMT +3:00) Istanbul, Baghdad, Riyadh, Moscow, St. Petersburg, Nairobi',
        '3.500' => '(GMT +3:30) Tehran',
        '4.000' => '(GMT +4:00) Abu Dhabi, Muscat, Baku, Tbilisi',
        '4.500' => '(GMT +4:30) Kabul',
        '5.000' => '(GMT +5:00) Ekaterinburg, Islamabad, Karachi, Tashkent',
        '5.500' => '(GMT +5:30) Bombay, Calcutta, Madras, New Delhi',
        '5.750' => '(GMT +5:45) Kathmandu',
        '6.000' => '(GMT +6:00) Almaty, Dhaka, Colombo, Krasnoyarsk',
        '7.000' => '(GMT +7:00) Bangkok, Hanoi, Jakarta',
        '8.000' => '(GMT +8:00) Beijing, Singapore, Hong Kong',
        '8.001' => '(GMT +8:00) Perth, Western Australia (No Daylight Savings)',
        '9.000' => '(GMT +9:00) Tokyo, Osaka, Sapporo, Yakutsk',
        '9.001' => '(GMT +9:00) Seoul, South Korea',
        '9.500' => '(GMT +9:30) Adelaide, Darwin, the Northern Territory',
        '10.000' => '(GMT +10:00) Eastern Australia, Guam, Vladivostok',
        '10.001' => '(GMT +10:00) Brisbane, Queensland (No Daylight Savings)',
        '10.002' => '(GMT +10:00) Melbourne',
        '11.000' => '(GMT +11:00) Magadan, Solomon Islands, New Caledonia',
        '12.000' => '(GMT +12:00) Auckland, Wellington, Fiji, Kamchatka',
    ];

    public function edit(Request $request, string $go = 'default'): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        if (! in_array($go, self::GO_TABS, true)) {
            return redirect('/admin/site-preferences');
        }

        $ctx = TenantContext::load();
        $set = $ctx->prefsStr('prefsStyleSet') ?? '';

        return view('admin.site-preferences', [
            'ctx' => $ctx,
            'go' => $go,
            'styleTypes' => DB::table('style_types')->orderBy('id')->get(),
            // Per-style-type entry limits: only the BOS style types carry one
            // (site_preferences.admin.php:2048-2065).
            'styleTypesBos' => DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get(),
            'languages' => self::LANGUAGES,
            'timezones' => self::TIMEZONES,
            'styleSet' => $set,
            // Picker source: the six sets from the single definition.
            'styleSets' => StyleSets::all(),
            'styleLimitRows' => $this->styleLimitRows($set),
            // Active-set styles offered as per-sub-style limit exceptions
            // (legacy $prefsUSCLEx checkbox group).
            'styleExceptions' => $this->styleExceptions($set),
            // Legacy $incremental_limits: the stored per-participant tiered
            // limits, keyed 1..4 with {limit-number, limit-days}.
            'incrementalLimits' => json_decode((string) $ctx->prefsStr('prefsUserEntryLimitDates'), true) ?: [],
            // Entry-window open epoch, used for the incremental tier date hints.
            'entryOpen' => (int) ($ctx->contestStr('contestEntryOpen') ?? 0),
            // Installation default for the blank Session Timeout placeholder
            // (legacy $session_expire_after in config.php).
            'sessionTimeoutDefault' => (int) config('session.lifetime', 120),
        ]);
    }

    /**
     * Per-style limit grid rows for a style set: group key + label + current limit.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private function styleLimitRows(string $set): array
    {
        $limits = json_decode((string) TenantContext::load()->prefsStr('prefsStyleLimits'), true) ?: [];

        return array_values($this->activeStyleCategoryQuery($set)->get()
            ->map(fn ($s): array => [
                'key' => (string) $s->brewStyleGroup,
                'label' => (string) $s->brewStyleGroup.' - '.($s->brewStyleCategory ?: $s->brewStyle),
                'value' => (string) ($limits[$s->brewStyleGroup] ?? ''),
            ])
            ->all());
    }

    /** Style query matching the rebuildSelectedStyles active-set predicate. */
    private function activeStyleCategoryQuery(string $set): Builder
    {
        return StyleSets::activeQuery($set)
            ->select('brewStyleGroup', 'brewStyleCategory', 'brewStyle')
            ->orderBy('brewStyleGroup');
    }

    /**
     * Active-set styles for the per-sub-style limit exception checkbox group
     * (legacy $prefsUSCLEx / site_preferences.admin.php:224-253). Only the
     * active set is listed — the port rebuilds prefsSelectedStyles on a set
     * change, so the other sets' lists would be dead weight.
     *
     * @return list<array{id: int, group: string, label: string}>
     */
    private function styleExceptions(string $set): array
    {
        $noNumbering = StyleSets::noNumbering($set);
        $separator = StyleSets::separator($set);

        return array_values(StyleSets::activeQuery($set)
            ->select('id', 'brewStyleGroup', 'brewStyleNum', 'brewStyle')
            ->orderBy('brewStyleGroup')->orderBy('brewStyleNum')
            ->get()
            ->map(function ($s) use ($noNumbering, $separator): array {
                $group = ltrim((string) $s->brewStyleGroup, '0') ?: '0';
                $number = trim($group.$separator.(string) $s->brewStyleNum, $separator);

                return [
                    'id' => (int) $s->id,
                    'group' => $group,
                    'label' => $noNumbering ? (string) $s->brewStyle : trim($number.' '.(string) $s->brewStyle),
                ];
            })
            ->all());
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

        // Tenant schemas vary (SCABS fork drops prefsLanguageToggle/Options);
        // write only columns this database actually has.
        $existing = collect(DB::getSchemaBuilder()->getColumnListing('preferences'))->flip();
        $update = collect($update)->only($existing->keys()->all())->all();
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
            // The port ships two palettes (default public + brux); the legacy
            // Bootswatch names no longer exist, so reject anything else.
            'prefsTheme' => ['required', Rule::in(['default', 'bcoem-brux'])],
            'prefsSEF' => ['required', 'in:Y,N'],
            // Custom Modules: legacy stores Y/N in a char(1) column and both
            // the dashboard and the public mods gate test for 'Y'.
            'prefsUseMods' => ['required', 'in:Y,N'],
            'prefsCAPTCHA' => ['nullable', 'in:0,1'],
            'prefsGoogleAccount' => ['nullable', 'string', 'max:255'],
            'prefsRecordPaging' => ['nullable', 'integer'],
            'prefsDropOff' => ['required', 'in:0,1,Y,N'],
            'prefsShipping' => ['required', 'in:0,1,Y,N'],
            'prefsAutoPurge' => ['required', 'in:0,1'],
            'prefsLanguage' => ['required', 'string', 'max:10'],
            'prefsLanguageToggle' => ['required', 'in:Y,N'],
            'prefsLanguageOptions' => ['nullable', 'array'],
            'prefsDateFormat' => ['required', 'in:0,1,2,999'],
            'prefsTimeFormat' => ['required', 'in:0,1'],
            'prefsTimeZone' => ['required', 'string', 'max:10'],
            'prefsSponsors' => ['required', 'in:Y,N'],
            'prefsSponsorLogos' => ['required', 'in:Y,N'],
            // Session (auto-logout) timeout, upstream 3.1.0. Blank or
            // zero-or-less stores NULL ("use the installation default"); a
            // value below the 3-minute floor is clamped up in sessionTimeout().
            // Only non-numeric input is rejected outright (legacy silently
            // blanked it) — the form's type="number" already prevents it.
            'prefsSessionTimeout' => ['nullable', 'integer'],
        ]);
        $data = $this->validateDates($request, $data, ['prefsWinnerDelay'], $tz);

        // Turnstile: catch "enabled but no keys" at save time, not later as a
        // mystery failed signup. Keys are stored combined as "site|secret".
        if (($data['prefsCAPTCHA'] ?? '0') === '1') {
            $parts = array_map('trim', explode('|', (string) ($data['prefsGoogleAccount'] ?? ''), 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw ValidationException::withMessages([
                    'prefsGoogleAccount' => 'Enter both the Turnstile site key and secret key (site|secret) to enable bot protection, or turn it off.',
                ]);
            }
        }

        // Pro edition suppresses the MHP display (legacy quirk).
        $mhp = $data['prefsProEdition'] == 1 ? '0' : (string) ($data['prefsMHPDisplay'] ?? '0');

        // Legacy stores the reCAPTCHA account as pipe-joined parts; the form
        // posts the combined value directly.
        $google = (string) ($data['prefsGoogleAccount'] ?? '');

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
            'prefsSessionTimeout' => $this->sessionTimeout($data['prefsSessionTimeout'] ?? null),
            'prefsRecordPaging' => self::blankToNull((string) ($data['prefsRecordPaging'] ?? '')),
        ];
    }

    /**
     * process_prefs.inc.php session-timeout semantics: blank/non-numeric/
     * zero-or-less stored as NULL, which the runtime treats as "use the
     * installation default" (TenantContext::sessionTimeoutMinutes()). A value
     * that's too low is clamped up to a 3-minute floor — the auto-logout
     * warning modals fire at 2:00 and 0:30 remaining, so anything at or below
     * that leaves no room for a normal countdown and traps the user.
     * (Validation rejects non-integers outright; legacy silently blanked them.)
     */
    private function sessionTimeout(mixed $value): ?int
    {
        $minutes = is_numeric($value) ? (int) $value : 0;

        if ($minutes < 1) {
            return null;
        }

        return max(3, $minutes);
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
            'contestEntryFeePasswordNum' => ['nullable', 'numeric', 'min:0'],
            'contestEntryCap' => ['nullable', 'integer', 'min:1'],
            'prefsStyleSet' => ['required', Rule::in(StyleSets::names())],
            'prefsEntryForm' => ['required', 'integer'],
            'prefsSpecific' => ['required', 'in:0,1'],
            'prefsSpecialCharLimit' => ['required', 'integer', 'min:25', 'max:255'],
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

        // Per-style-type entry limits live on style_types, and only the BOS
        // types are posted. style_type_entry_limits is the hidden comma-list of
        // their ids (site_preferences.admin.php:2048-2066 /
        // process_prefs.inc.php:339-357).
        foreach (explode(',', (string) $request->input('style_type_entry_limits', '')) as $id) {
            $id = (int) trim($id);
            if ($id < 1) {
                continue;
            }
            DB::table('style_types')->where('id', $id)->update([
                'styleTypeEntryLimit' => self::blankToNull(trim((string) $request->input('styleTypeEntryLimit-'.$id, ''))),
            ]);
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
            if (is_string($key) && str_starts_with($key, 'styleEntryLimit-'.$set.'-') && $value !== '' && $value !== null) {
                $parts = explode('-', $key);
                $limits[$parts[2]] = (string) $value;
            }
        }

        return $limits === [] ? null : json_encode($limits, JSON_THROW_ON_ERROR);
    }

    /** Ledger pins #5/#6/#7: dual-version OR-closure + customs, all sets. */
    private function rebuildSelectedStyles(string $set): void
    {
        $query = StyleSets::activeQuery($set);

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
            'prefsContact' => ['required', 'in:Y,N,X'],
            'prefsEmailRegConfirm' => ['required', 'in:0,1'],
            'change-email-password-choice' => ['required', 'in:0,1'],
            'prefsEmailPassword' => ['nullable', 'string', 'max:255'],
            'prefsEmailFrom' => ['nullable', 'string', 'max:255'],
            'prefsEmailUsername' => ['nullable', 'string', 'max:255'],
            'prefsEmailHost' => ['nullable', 'string', 'max:255'],
            'prefsEmailEncrypt' => ['nullable', 'string', 'max:10'],
            'prefsEmailPort' => ['nullable', 'integer'],
            'prefsEmailCC' => ['nullable', 'in:0,1'],
            // Transport selection (MailSettings). Optional so a form posted
            // without the field — or an older install — keeps working.
            'prefsEmailTransport' => ['nullable', 'in:'.implode(',', MailSettings::TRANSPORTS)],
            'prefsEmailApiKey' => ['nullable', 'string', 'max:255'],
        ]);

        $from = (string) ($data['prefsEmailFrom'] ?? '');
        $username = trim((string) ($data['prefsEmailUsername'] ?? ''));
        $host = (string) ($data['prefsEmailHost'] ?? '');
        $encrypt = (string) ($data['prefsEmailEncrypt'] ?? '');
        $port = (string) ($data['prefsEmailPort'] ?? '');
        $password = trim((string) ($data['prefsEmailPassword'] ?? ''));
        $confirm = (string) $data['prefsEmailRegConfirm'];
        $cc = (string) ($data['prefsEmailCC'] ?? '0');
        $transport = strtolower(trim((string) ($data['prefsEmailTransport'] ?? '')));
        $apiKey = trim((string) ($data['prefsEmailApiKey'] ?? ''));

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
            $transport = strtolower(trim((string) ($stored['prefsEmailTransport'] ?? '')));
            $apiKey = trim((string) ($stored['prefsEmailApiKey'] ?? ''));
            $confirm = '0';
            $cc = '0';
        }

        // An API key left blank on an unchanged provider must not wipe the
        // stored secret (the field is never pre-filled with it).
        if ($apiKey === '') {
            $apiKey = trim((string) ($stored['prefsEmailApiKey'] ?? ''));
        }

        return [
            'prefsEmailSMTP' => (string) $data['prefsEmailSMTP'],
            'prefsEmailFrom' => self::blankToNull($from),
            'prefsEmailUsername' => self::blankToNull($username),
            'prefsEmailPassword' => self::blankToNull($password),
            'prefsEmailHost' => self::blankToNull($host),
            'prefsEmailEncrypt' => self::blankToNull($encrypt),
            'prefsEmailPort' => self::blankToNull($port),
            'prefsEmailTransport' => self::blankToNull($transport),
            'prefsEmailApiKey' => self::blankToNull($apiKey),
            'prefsContact' => self::blankToNull((string) $data['prefsContact']),
            'prefsEmailRegConfirm' => $confirm,
            'prefsEmailCC' => $cc,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayment(Request $request): array
    {
        $data = $request->validate([
            // The column is varchar(20) and the legacy list carries values
            // longer than 5 (czkoruna, phpeso, sfranc, shekel), so a max:5
            // here made four of the offered currencies unsaveable.
            'prefsCurrency' => ['required', 'string', 'max:20'],
            'prefsPayToPrint' => ['required', 'in:0,1'],
            'prefsCash' => ['required', 'in:0,1'],
            'prefsCheck' => ['required', 'in:0,1'],
            'prefsCheckPayee' => ['nullable', 'string', 'max:255'],
            'prefsTransFee' => ['required', 'in:Y,N'],
        ]);

        return [
            'prefsCurrency' => (string) $data['prefsCurrency'],
            'prefsPayToPrint' => (string) $data['prefsPayToPrint'],
            'prefsCash' => (string) $data['prefsCash'],
            'prefsCheck' => (string) $data['prefsCheck'],
            'prefsCheckPayee' => self::blankToNull((string) ($data['prefsCheckPayee'] ?? '')),
            'prefsTransFee' => (string) $data['prefsTransFee'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateBest(Request $request): array
    {
        $data = $request->validate([
            // Legacy selects run -1 (display all) .. 50 (site_preferences.admin.php:1562-1565).
            'prefsShowBestBrewer' => ['required', 'integer', 'min:-1', 'max:50'],
            'prefsBestBrewerTitle' => ['nullable', 'string', 'max:100'],
            'prefsShowBestClub' => ['required', 'integer', 'min:-1', 'max:50'],
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
