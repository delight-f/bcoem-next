<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Entries\EntryLimits;
use App\Support\Entries\JudgingNumber;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Entry creation (P3.3a) — port of `pub/brew.pub.php` (add mode) plus the
 * add branch of `includes/process/process_brewing.inc.php`.
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

    public function showCreate(): View|RedirectResponse
    {
        $brewer = DB::table('brewer')->where('uid', Auth::id())->first();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();

        return view('brew.create', [
            'ctx' => $ctx,
            'styles' => self::activeStyles($ctx),
            // Variant fieldsets render when the previously posted (failed
            // validation) style requires them.
            'variantFlags' => self::styleFlags(is_string(old('brewStyle')) ? old('brewStyle') : null, $ctx),
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

        return $group.'.'.$num.': '.$style->brewStyle;
    }

    /**
     * Style row for a posted `<cat>-<sub>` code — the same lookup the add
     * branch uses to fill brewStyle/brewStyleType and gate the mead/cider
     * variant fields (process_brewing.inc.php:335-344). Null when the code
     * is absent or matches no row of the active set (or its customs).
     */
    public static function styleFlags(?string $code, TenantContext $ctx): ?\stdClass
    {
        if ($code === null || ! str_contains($code, '-')) {
            return null;
        }

        $set = $ctx->prefsStr('prefsStyleSet');
        // First char of group 'C' picks the coexisting BJCP2025 rows (#6).
        $version = $set === 'BJCP2025' && mb_substr(self::styleSort($code), 0, 1) === 'C'
            ? 'BJCP2025'
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
        $cat = explode('-', $code)[0];

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
}
