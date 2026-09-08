<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Entries\EntryGates;
use App\Support\Entries\EntryLimits;
use App\Support\Entries\JudgingNumber;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Entry creation + edit (P3.3a/P3.3b) — port of `pub/brew.pub.php` (add +
 * edit modes) plus the matching branches of `includes/process/
 * process_brewing.inc.php`.
 *
 * The legacy form posts hidden brewBrewerID/name fields; the port derives
 * them from the session (process_brewing.inc.php:33 only lets non-admins
 * submit under their own ID anyway) and ignores admin-only columns
 * (brewAdminNotes/brewStaffNotes/brewBoxNum — userLevel<=1 POST reads).
 *
 * Caps gate creation via the EntryLimits engine (ticket 11): per-user total
 * cap counts ALL brewing rows (unconfirmed drafts and unpaid included),
 * subcategory cap counts exact sort+sub equality (BA style set drops the
 * category filter), admins bypass both. Redirects carry the legacy msg
 * codes (`section=list&msg=8` user cap, `msg=9` subcat).
 * Free comp forces paid (entry-lifecycle #2): contestEntryFee==0 ⇒
 * brewPaid=1 at insert. Fee-snapshot for the pay page is P3.5d scope.
 * Edit gating runs through EntryGates (ticket 10).
 */
final class BrewController extends Controller
{
    /** @var array<string, list<string>> */
    private const RULES = [
        'brewName' => ['required', 'string', 'max:250'],
        // Posted code is `<category>-<subcategory>`; explode('-') means the
        // subcategory must not contain '-' (styles ledger #1).
        'brewStyle' => ['required', 'string', 'regex:/^[^-]+-[^-]+$/'],
        'brewCoBrewer' => ['nullable', 'string', 'max:255'],
        'brewInfo' => ['nullable', 'string'],
        'brewInfoOptional' => ['nullable', 'string', 'max:65535'],
        'brewComments' => ['nullable', 'string', 'max:65535'],
        'brewABV' => ['nullable', 'numeric', 'min:0'],
        'brewOriginalGravity' => ['nullable', 'numeric', 'min:0'],
        'brewFinalGravity' => ['nullable', 'numeric', 'min:0'],
        'brewSweetnessLevel' => ['nullable', 'numeric', 'min:0'],
        'brewMead1' => ['nullable', 'string', 'max:25'],
        'brewMead2-cider' => ['nullable', 'string', 'max:25'],
        'brewMead2-mead' => ['nullable', 'string', 'max:25'],
        'brewMead3' => ['nullable', 'string', 'max:25'],
        'brewJuiceSource' => ['nullable', 'array'],
        'brewJuiceSource.*' => ['string', 'max:100'],
        'brewJuiceSourceOther' => ['nullable', 'array'],
        'brewJuiceSourceOther.*' => ['string', 'max:100'],
        'brewPouringInst' => ['nullable', 'string', 'max:50'],
        'brewPouringRouse' => ['nullable', 'string', 'max:20'],
        'brewPouringNotes' => ['nullable', 'string', 'max:255'],
        'brewPackaging' => ['nullable', 'string', 'max:255'],
        'brewPossAllergens' => ['nullable', 'string', 'max:255'],
    ];

    public function showCreate(Request $request): View|RedirectResponse
    {
        $brewer = DB::table('brewer')->where('uid', Auth::id())->first();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();

        // Legacy brew.sec.php:112 — once the entry window closes, adding is
        // disabled for entrants (userLevel > 1); admins keep the form. The
        // save path itself is NOT window-gated in legacy (process_brewing.inc.php),
        // so this is purely a render gate.
        $windows = Windows::derive($ctx, time());
        $addAllowed = $windows->entry === WindowState::Open
            || (bool) ($request->user()?->isAdmin() ?? false);

        if (! $addAllowed) {
            return view('brew.closed', [
                'ctx' => $ctx,
                'salutation' => __('site.add_entry'),
            ]);
        }

        return view('brew.create', [
            'ctx' => $ctx,
            'brewer' => $brewer,
            'styles' => self::activeStyles($ctx),
            // Variant fieldsets render when the previously posted (failed
            // validation) style requires them.
            'variantFlags' => self::styleFlags(is_string(old('brewStyle')) ? old('brewStyle') : null, $ctx),
            'optionalStyles' => self::optionalInfoStyles($ctx->prefsStr('prefsStyleSet') ?? ''),
            'styleFlagMap' => self::styleFlagMap(self::activeStyles($ctx)),
            'salutation' => __('site.add_entry'),
        ]);
    }

    public function storeCreate(Request $request): RedirectResponse
    {
        $userId = (int) Auth::id();

        $brewer = DB::table('brewer')->where('uid', $userId)->first();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();
        $data = $request->validate(self::RULES);

        $sort = self::styleSort($data['brewStyle']);
        $sub = self::styleSub($data['brewStyle']);
        $styleRow = self::styleFlags($data['brewStyle'], $ctx);

        // Legacy order: the msg=8/msg=9 cap redirects run before the
        // $process_allowed_entries gate (process_brewing.inc.php:42-84);
        // EntryLimits.check mirrors that. The create flow guarantees
        // ownership by construction.
        $limits = EntryLimits::check(
            action: 'add',
            userLevel: (int) ($request->user()->userLevel ?? 2),
            ownsEntry: true,
            entryLimitEnabled: self::compEntryLimitReached($ctx),
            paidLimitEnabled: self::compPaidEntryLimitReached($ctx),
            userEntryLimit: $ctx->prefsStr('prefsUserEntryLimit'),
            userEntryCount: DB::table('brewing')->where('brewBrewerID', $userId)->count(),
            style: $data['brewStyle'],
            previousStyle: null,
            editWindowOpen: false,
            subCatLimit: $ctx->prefsStr('prefsUserSubCatLimit'),
            exceptionSubNum: (string) ($ctx->prefsStr('prefsUSCLExLimit') ?? ''),
            exceptionSubList: (string) ($ctx->prefsStr('prefsUSCLEx') ?? ''),
            styleId: $styleRow?->id,
            subCategoryCount: self::subCategoryCount($ctx, $userId, $data['brewStyle']),
        );

        if (! $limits->allowed) {
            // '8' user cap / '9' subcat cap — legacy redirect codes.
            return redirect('/list?msg='.$limits->reason);
        }

        $mead1 = $mead2 = $mead3 = null;
        if ($styleRow !== null) {
            // Variant fields are only kept when the style row asks for them
            // (process_brewing.inc.php:390-397).
            if (($data['brewMead1'] ?? null) !== null && (int) $styleRow->brewStyleCarb === 1) {
                $mead1 = self::blankToNull($data['brewMead1']);
            }
            if ((isset($data['brewMead2-cider']) || isset($data['brewMead2-mead']))
                && (int) $styleRow->brewStyleSweet === 1 && (int) $styleRow->brewStyleType === 2) {
                $mead2 = self::blankToNull($data['brewMead2-cider'] ?? $data['brewMead2-mead']);
            }
            if ((isset($data['brewMead2-cider']) || isset($data['brewMead2-mead']))
                && (int) $styleRow->brewStyleSweet === 1 && (int) $styleRow->brewStyleType === 3) {
                $mead2 = self::blankToNull($data['brewMead2-mead'] ?? $data['brewMead2-cider']);
            }
            if (($data['brewMead3'] ?? null) !== null && (int) $styleRow->brewStyleStrength === 1) {
                $mead3 = self::blankToNull($data['brewMead3']);
            }
        }

        // OG/FG readings become a JSON sweetness level; a direct specific-
        // gravity value (NW Cider Cup styles) is formatted to 3 decimals.
        $og = $data['brewOriginalGravity'] ?? null;
        $fg = $data['brewFinalGravity'] ?? null;
        if ($og !== null || $fg !== null) {
            $sweetness = json_encode([
                'OG' => number_format((float) $og, 3, '.', ''),
                'FG' => number_format((float) $fg, 3, '.', ''),
            ]);
        } elseif (($data['brewSweetnessLevel'] ?? '') !== '') {
            $sweetness = number_format((float) $data['brewSweetnessLevel'], 3, '.', '');
        } else {
            $sweetness = null;
        }

        $juice = [];
        if (isset($data['brewJuiceSource'])) {
            $juice['juice_src'] = array_values($data['brewJuiceSource']);
        }
        if (isset($data['brewJuiceSourceOther'])) {
            $juice['juice_src_other'] = array_values($data['brewJuiceSourceOther']);
        }

        // Legacy always stores JSON here ("[]" when nothing was entered).
        $pouring = [];
        if (($data['brewPouringInst'] ?? '') !== '') {
            $pouring['pouring'] = $data['brewPouringInst'];
        }
        if (($data['brewPouringRouse'] ?? '') !== '') {
            $pouring['pouring_rouse'] = $data['brewPouringRouse'];
        }
        if (($data['brewPouringNotes'] ?? '') !== '') {
            $pouring['pouring_notes'] = $data['brewPouringNotes'];
        }

        DB::table('brewing')->insert([
            'brewName' => self::blankToNull($data['brewName']),
            'brewStyle' => $styleRow->brewStyle ?? null,
            'brewCategory' => self::blankToNull(ltrim($sort, '0')),
            'brewCategorySort' => $sort,
            'brewSubCategory' => self::blankToNull($sub),
            'brewInfo' => self::blankToNull($data['brewInfo'] ?? ''),
            'brewMead1' => $mead1,
            'brewMead2' => $mead2,
            'brewMead3' => $mead3,
            'brewComments' => self::blankToNull($data['brewComments'] ?? ''),
            'brewBrewerID' => (string) $userId,
            'brewBrewerFirstName' => $brewer->brewerFirstName,
            'brewBrewerLastName' => $brewer->brewerLastName,
            'brewPaid' => ((float) ($ctx->contestStr('contestEntryFee') ?? 0)) == 0 ? 1 : 0,
            'brewReceived' => 0,
            'brewConfirmed' => '1',
            'brewInfoOptional' => self::blankToNull($data['brewInfoOptional'] ?? ''),
            'brewAdminNotes' => null,
            'brewStaffNotes' => null,
            'brewBoxNum' => null,
            'brewPossAllergens' => self::blankToNull($data['brewPossAllergens'] ?? ''),
            'brewCoBrewer' => self::blankToNull($data['brewCoBrewer'] ?? ''),
            'brewJudgingNumber' => JudgingNumber::random(),
            'brewUpdated' => now()->format('Y-m-d H:i:s'),
            'brewABV' => $data['brewABV'] ?? null,
            'brewJuiceSource' => $juice === [] ? null : json_encode($juice),
            'brewSweetnessLevel' => $sweetness,
            'brewPouring' => json_encode($pouring),
            'brewStyleType' => $styleRow->brewStyleType ?? null,
            'brewPackaging' => self::blankToNull($data['brewPackaging'] ?? ''),
        ]);

        // Legacy success landing: ?section=list&msg=1.
        return redirect('/list?msg=1');
    }

    /**
     * Edit form (P3.3b) — pub/brew.pub.php's edit mode. Ownership is
     * brewBrewerID = Auth::id() (brew.pub.php:85-99 checks the entrant's
     * own entry ids; the port redirects instead of rendering a disabled
     * form), and the same lifecycle gate as the /list edit link applies
     * (EntryGates::edit — unreceived, judging not started, window open or
     * before the edit deadline).
     */
    public function showEdit(int $entry): View|RedirectResponse
    {
        $row = DB::table('brewing')
            ->where('id', $entry)
            ->where('brewBrewerID', (string) Auth::id())
            ->first();

        if ($row === null || ! self::editable($row)) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();
        $code = ltrim((string) $row->brewCategorySort, '0').'-'.$row->brewSubCategory;

        $brewer = DB::table('brewer')->where('uid', Auth::id())->first();

        return view('brew.edit', [
            'ctx' => $ctx,
            'entry' => $row,
            'brewer' => $brewer,
            'styles' => self::activeStyles($ctx),
            // Variant fieldsets render for the entry's stored style unless
            // a failed validation reposted another one.
            'variantFlags' => self::styleFlags(
                is_string($reposted = old('brewStyle')) ? $reposted : $code,
                $ctx,
            ),
            'optionalStyles' => self::optionalInfoStyles($ctx->prefsStr('prefsStyleSet') ?? ''),
            'styleFlagMap' => self::styleFlagMap(self::activeStyles($ctx)),
        ]);
    }

    /**
     * Edit save (P3.3b) — process_brewing.inc.php's edit branch.
     * Paid/received are admin-writable only; entrant POSTs re-read both
     * flags from the row loaded this request (:269-279). Style/category
     * fields freeze once the entry window closes (ledger #4). The judging
     * number is never reallocated (absent from the update map), and
     * brewUpdated is stamped on every save (:745). Missing required style
     * fields reset brewConfirmed='0' and serve the edit form back with the
     * legacy msg=1-<style> code (:776-902).
     */
    public function storeEdit(Request $request, int $entry): RedirectResponse
    {
        $userId = (int) Auth::id();

        $row = DB::table('brewing')
            ->where('id', $entry)
            ->where('brewBrewerID', (string) $userId)
            ->first();

        if ($row === null || ! self::editable($row)) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();
        /** @var array<string, mixed> $data */
        $data = $request->validate(self::RULES + [
            'brewPaid' => ['nullable', 'in:0,1'],
            'brewReceived' => ['nullable', 'in:0,1'],
        ]);

        // Style/category fields are only editable while the window is open;
        // afterwards a tampered code is ignored and the stored values win.
        $windowOpen = Windows::derive($ctx, time())->entry === WindowState::Open;
        $code = $windowOpen
            ? (string) $data['brewStyle']
            : ltrim((string) $row->brewCategorySort, '0').'-'.$row->brewSubCategory;
        $sort = self::styleSort($code);
        $sub = self::styleSub($code);
        $styleRow = self::styleFlags($code, $ctx);

        $isAdmin = (bool) ($request->user()?->isAdmin() ?? false);
        $paid = $isAdmin && isset($data['brewPaid']) ? (int) $data['brewPaid'] : (int) $row->brewPaid;
        $received = $isAdmin && isset($data['brewReceived']) ? (int) $data['brewReceived'] : (int) $row->brewReceived;

        // Variant values survive only when the style row asks for them;
        // the edit branch writes '' otherwise (:730-732 vs :390-397).
        $mead1 = $mead2 = $mead3 = '';
        if ($styleRow !== null) {
            if (($data['brewMead1'] ?? '') !== '' && (int) $styleRow->brewStyleCarb === 1) {
                $mead1 = (string) $data['brewMead1'];
            }
            if ((int) $styleRow->brewStyleSweet === 1 && (int) $styleRow->brewStyleType === 2
                && ($data['brewMead2-cider'] ?? '') !== '') {
                $mead2 = (string) $data['brewMead2-cider'];
            }
            if ((int) $styleRow->brewStyleSweet === 1 && (int) $styleRow->brewStyleType === 3
                && ($data['brewMead2-mead'] ?? '') !== '') {
                $mead2 = (string) $data['brewMead2-mead'];
            }
            if (($data['brewMead3'] ?? '') !== '' && (int) $styleRow->brewStyleStrength === 1) {
                $mead3 = (string) $data['brewMead3'];
            }
        }

        $info = (string) ($data['brewInfo'] ?? '');

        // Required style fields: special ingredients/classic-style info,
        // carbonation, sweetness, strength (:776-902). Any miss unconfirms
        // the row and serves the form back with msg=1-<style>.
        $confirmed = (string) ($data['brewConfirmed'] ?? '1');
        $missing =
            (self::requiresSpecInfo($code, $styleRow, $ctx) && $info === '')
            || ((int) ($styleRow->brewStyleCarb ?? 0) === 1 && $mead1 === '')
            || ((int) ($styleRow->brewStyleSweet ?? 0) === 1 && $mead2 === '')
            || ((int) ($styleRow->brewStyleStrength ?? 0) === 1 && $mead3 === '');
        if ($missing) {
            $confirmed = '0';
        }

        // OG/FG readings become a JSON sweetness level (shared with add);
        // juice source and pouring instructions keep legacy's JSON shapes.
        $sweetness = null;
        if (($data['brewOriginalGravity'] ?? null) !== null || ($data['brewFinalGravity'] ?? null) !== null) {
            $sweetness = json_encode([
                'OG' => number_format((float) ($data['brewOriginalGravity'] ?? 0), 3, '.', ''),
                'FG' => number_format((float) ($data['brewFinalGravity'] ?? 0), 3, '.', ''),
            ]);
        } elseif (($data['brewSweetnessLevel'] ?? '') !== '') {
            $sweetness = number_format((float) $data['brewSweetnessLevel'], 3, '.', '');
        }

        $juice = [];
        if (isset($data['brewJuiceSource'])) {
            $juice['juice_src'] = array_values((array) $data['brewJuiceSource']);
        }
        if (isset($data['brewJuiceSourceOther'])) {
            $juice['juice_src_other'] = array_values((array) $data['brewJuiceSourceOther']);
        }

        $pouring = [];
        if (($data['brewPouringInst'] ?? '') !== '') {
            $pouring['pouring'] = $data['brewPouringInst'];
        }
        if (($data['brewPouringRouse'] ?? '') !== '') {
            $pouring['pouring_rouse'] = $data['brewPouringRouse'];
        }
        if (($data['brewPouringNotes'] ?? '') !== '') {
            $pouring['pouring_notes'] = $data['brewPouringNotes'];
        }

        DB::table('brewing')->where('id', $entry)->update([
            'brewName' => (string) $data['brewName'],
            'brewStyle' => $styleRow->brewStyle ?? '',
            'brewCategory' => ltrim($sort, '0'),
            'brewCategorySort' => $sort,
            'brewSubCategory' => $sub,
            'brewInfo' => $info,
            'brewMead1' => $mead1,
            'brewMead2' => $mead2,
            'brewMead3' => $mead3,
            'brewComments' => (string) ($data['brewComments'] ?? ''),
            'brewPaid' => $paid,
            'brewInfoOptional' => (string) ($data['brewInfoOptional'] ?? ''),
            'brewPossAllergens' => (string) ($data['brewPossAllergens'] ?? ''),
            'brewCoBrewer' => (string) ($data['brewCoBrewer'] ?? ''),
            'brewReceived' => $received,
            'brewUpdated' => now()->format('Y-m-d H:i:s'),
            'brewConfirmed' => $confirmed,
            'brewABV' => self::blankToNull((string) ($data['brewABV'] ?? '')),
            'brewJuiceSource' => $juice === [] ? null : json_encode($juice),
            'brewSweetnessLevel' => self::blankToNull(is_string($sweetness) ? $sweetness : null),
            'brewPouring' => self::blankToNull(json_encode($pouring) ?: null),
            'brewStyleType' => $styleRow->brewStyleType ?? null,
            'brewPackaging' => self::blankToNull((string) ($data['brewPackaging'] ?? '')),
        ]);

        if ($confirmed === '0') {
            // Legacy non-admin landing for a missing required field:
            // back to the edit form with msg=1-<styleReturn> (:817).
            $index = ctype_digit(explode('-', $code)[0])
                ? sprintf('%02d', (int) explode('-', $code)[0])
                : explode('-', $code)[0];

            return redirect('/brew/'.$entry.'/edit?msg=1-'.$index.'-'.$sub);
        }

        // Legacy success landing: ?section=list&msg=2 (:778).
        return redirect('/list?msg=2');
    }

    /**
     * EntryGates::edit over the tenant windows — the same rule the /list
     * edit link renders (brewer_entries.pub.php:501/567): unreceived,
     * judging not started, and the window open or before its edit deadline.
     */
    private static function editable(\stdClass $row): bool
    {
        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());
        $now = time();

        return EntryGates::edit(
            (int) $row->brewReceived,
            $windows->entry === WindowState::Open,
            Windows::entryEditDeadline($ctx),
            $now,
            $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate,
        );
    }

    /**
     * check_special_ingredients() (common.lib.php:2907): the style demands
     * special-ingredient info when brewStyleReqSpec=1, except three BJCP2025
     * cider styles explicitly exempted there.
     */
    private static function requiresSpecInfo(string $code, ?\stdClass $styleRow, TenantContext $ctx): bool
    {
        if ($styleRow === null || (int) $styleRow->brewStyleReqSpec !== 1) {
            return false;
        }

        return ! ($ctx->prefsStr('prefsStyleSet') === 'BJCP2025'
            && in_array(self::styleSort($code).'-'.self::styleSub($code), ['C2-C', 'C2-D', 'C4-C'], true));
    }

    /**
     * Styles dropdown source: active style set rows + customs extend every
     * set (styles ledger #5/#6), restricted to the organizer-selected ids
     * (prefsSelectedStyles), ordered like the legacy brew-section query
     * (styles.db.php:79-81).
     *
     * @return Collection<int, \stdClass>
     */
    public static function activeStyles(TenantContext $ctx): Collection
    {
        $set = $ctx->prefsStr('prefsStyleSet');
        $query = DB::table('styles')->where(function ($q) use ($set): void {
            if ($set === 'BJCP2025') {
                // First char of group 'C' → BJCP2025 rows; everything else
                // BJCP2021 (styles ledger #6).
                $q->where(function ($qq): void {
                    $qq->where('brewStyleVersion', 'BJCP2025')->where('brewStyleType', '2');
                })->orWhere(function ($qq): void {
                    $qq->where('brewStyleVersion', 'BJCP2021')->where('brewStyleType', '!=', '2');
                });
            } else {
                $q->where('brewStyleVersion', $set);
            }
            $q->orWhere('brewStyleOwn', 'custom');
        });

        $selected = json_decode((string) $ctx->prefsStr('prefsSelectedStyles'), true);
        if (is_array($selected) && $selected !== []) {
            $query->whereIn('id', array_map(intval(...), array_keys($selected)));
        }

        $order = $set === 'BA'
            ? ['brewStyleType', 'brewStyleGroup', 'brewStyle']
            : ['brewStyleType', 'brewStyleGroup', 'brewStyleNum'];

        return $query->orderBy($order[0])->orderBy($order[1])->orderBy($order[2])->get();
    }

    /**
     * Option value for a styles row, matching style_number_const(): system
     * separator '-', group leading zeros trimmed.
     */
    public static function styleValue(\stdClass $style): string
    {
        return ltrim((string) $style->brewStyleGroup, '0').'-'.(string) $style->brewStyleNum;
    }

    /**
     * Display label, matching the BJCP/other branches of
     * style_number_const(method 0) with the '.' display separator.
     */
    public static function styleLabel(\stdClass $style): string
    {
        $group = str_contains((string) $style->brewStyleVersion, 'BJCP')
            ? ltrim((string) $style->brewStyleGroup, '0')
            : (string) $style->brewStyleGroup;
        $num = str_contains((string) $style->brewStyleNum, 'BJCP')
            ? ltrim((string) $style->brewStyleNum, '0')
            : (string) $style->brewStyleNum;

        // Legacy brew-entry select option text (brew.sec.php:187):
        // style_number_const(...)." ".brewStyle — e.g. "1A American Amber
        // Ale", no colon. (The judging-prefs checkboxes DO use "1A: Name"
        // per brewer_form_2.sec.php:58 — that label lives in
        // BrewerForm2Controller, not here.)
        return $group.$num.' '.$style->brewStyle;
    }

    /**
     * Styles whose Optional Info field shows on the entry form — the
     * per-style-set lists from includes/constants.inc.php:566-591.
     *
     * @return list<string>
     */
    public static function optionalInfoStyles(string $set): array
    {
        return match ($set) {
            'BA' => [],
            'AABC' => ['12-01', '14-08', '17-03', '18-04', '18-05', '19-05', '19-07', '16-01', '19-01', '19-02', '19-03', '19-04', '19-06', '20-02', '20-03'],
            'AABC2022' => ['07-03', '12-01', '14-08', '17-03', '18-04', '18-05', '16-01', '19-01', '19-02', '19-03', '19-04', '19-05', '19-06', '19-07', '19-08', '19-09', '19-10', '19-11', '19-12', '19-13', '20-02', '20-03', '16-08'],
            'AABC2025' => ['07-03', '12-01', '14-08', '17-03', '18-04', '18-05', '16-01', '16-08', '19-01', '19-02', '19-03', '19-04', '19-05', '19-06', '19-07', '19-08', '19-09', '19-10', '19-11', '19-12', '19-13', '20-01', '20-02', '20-03', '20-04', '20-05', '20-10', '20-11', '20-12', '20-16'],
            'NWCiderCup' => ['C4-A', 'C4-B', 'C5-A', 'C8-A', 'C8-B', 'C8-C', 'C9-A', 'C9-B', 'C9-C'],
            default => array_merge(
                ['21-B', '28-A', '30-B', '33-A', '33-B', '34-B', 'M2-C', 'M2-D', 'M2-E', 'M3-A', 'M3-B', 'M4-B', 'M4-C', '7-C', 'M1-A', 'M1-B', 'M1-C', 'M2-A', 'M2-B', 'M4-A', 'C1-A', 'C1-B', 'C1-C'],
                $set === 'BJCP2021' ? ['25-B'] : [],
                $set === 'BJCP2025' ? ['C1-D', 'C1-E', 'C3-A', 'C3-B', 'C3-C', 'C4-D'] : [],
            ),
        };
    }

    public static function styleFlags(?string $code, TenantContext $ctx): ?\stdClass
    {
        if ($code === null || ! str_contains($code, '-')) {
            return null;
        }

        $set = $ctx->prefsStr('prefsStyleSet');
        // Under the BJCP2025 set only C-groups read the 2025 rows; every
        // other group falls back to BJCP2021, matching legacy style
        // resolution (process_brewing.inc.php:335-341 — season-sim §8.3).
        $version = $set === 'BJCP2025'
            ? (mb_substr(self::styleSort($code), 0, 1) === 'C' ? 'BJCP2025' : 'BJCP2021')
            : $set;

        $row = DB::table('styles')
            ->where('brewStyleGroup', self::styleSort($code))
            ->where('brewStyleNum', self::styleSub($code))
            ->where(function ($q) use ($version): void {
                $q->where('brewStyleVersion', $version)->orWhere('brewStyleOwn', 'custom');
            })
            ->first();

        return $row === null ? null : (object) $row;
    }

    /**
     * Category as stored in brewCategorySort: numeric categories < 10 are
     * zero-padded, alpha categories never (styles ledger #2/#3).
     */
    private static function styleSort(string $code): string
    {
        // ltrim first so already-padded input ('01') canonicalizes instead
        // of re-padding to an unresolvable '001' (styles ledger #2/#4).
        $cat = ltrim(explode('-', $code)[0], '0');

        return ctype_digit($cat) && (int) $cat < 10 ? '0'.$cat : $cat;
    }

    private static function styleSub(string $code): string
    {
        return explode('-', $code)[1] ?? '';
    }

    /**
     * Subcategory count for the caps engine: exact brewCategorySort+
     * brewSubCategory equality under this brewer; the BA style set drops
     * the category filter (registration-rules #3/#4 — WHERE shape comes
     * from EntryLimits::countFilters).
     */
    private static function subCategoryCount(TenantContext $ctx, int $userId, string $style): int
    {
        $filters = EntryLimits::countFilters($style, $ctx->prefsStr('prefsStyleSet') === 'BA');
        $query = DB::table('brewing')->where('brewBrewerID', $userId);

        if ($filters['categorySort'] !== null) {
            $query->where('brewCategorySort', $filters['categorySort']);
        }

        return (int) $query->clone()->where('brewSubCategory', $filters['subCategory'])->count();
    }

    /**
     * Comp-level cap flags (constants.inc.php:447-448): total entry count /
     * paid entry count against prefsEntryLimit / prefsEntryLimitPaid. False
     * when no limit is configured (#9).
     */
    private static function compEntryLimitReached(TenantContext $ctx): bool
    {
        $limit = $ctx->prefsStr('prefsEntryLimit');

        return $limit !== null && $limit !== '' && is_numeric($limit)
            && DB::table('brewing')->count() >= (int) $limit;
    }

    private static function compPaidEntryLimitReached(TenantContext $ctx): bool
    {
        $limit = $ctx->prefsStr('prefsEntryLimitPaid');

        return $limit !== null && $limit !== '' && is_numeric($limit)
            && DB::table('brewing')->where('brewPaid', '1')->count() >= (int) $limit;
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Per-style client-side flag map for the entry form's show/hide JS
     * (entry.min.js parity): keyed by the <option> style code.
     *
     * @param  Collection<int, \stdClass>  $styles
     * @return array<string, array{reqSpec: bool, carb: bool, sweet: bool, strength: bool, type: string, group: string, num: string, entry: string}>
     */
    private static function styleFlagMap(Collection $styles): array
    {
        return $styles->mapWithKeys(fn (\stdClass $s): array => [
            self::styleValue($s) => [
                'reqSpec' => (int) $s->brewStyleReqSpec === 1,
                'carb' => (int) $s->brewStyleCarb === 1,
                'sweet' => (int) $s->brewStyleSweet === 1,
                'strength' => (int) $s->brewStyleStrength === 1,
                'type' => (string) $s->brewStyleType,
                'group' => ltrim((string) $s->brewStyleGroup, '0'),
                'num' => (string) $s->brewStyleNum,
                'entry' => (string) ($s->brewStyleEntry ?? ''),
            ],
        ])->all();
    }
}
