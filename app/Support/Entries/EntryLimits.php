<?php

declare(strict_types=1);

namespace App\Support\Entries;

/**
 * Entry limits engine (ticket 11). Answers "can this brewer add/edit this
 * entry right now?" — one source of truth for create/edit/count/nav gating.
 *
 * Ledger mapping (ledger/registration-rules.md, 1:1):
 *   #1 per-user total cap counts ALL brewing rows (incl. unconfirmed
 *      drafts/unpaid) — the count is passed in as $userEntryCount; the DB
 *      COUNT(*) shape is pinned in RegistrationRulesDbTest.
 *   #2 at/over user cap on add ⇒ reason '8' (?section=list&msg=8).
 *   #3 subcategory count = brewBrewerID + brewCategorySort + brewSubCategory
 *      exact equality (countFilters(); padded sort via normalizeCategory()).
 *   #4 BA style set drops the category filter in the subcat count.
 *   #5 subcat limit checked on add; on edit only when window open AND style
 *      actually changed.
 *   #6 category normalization: numeric ≤9 zero-padded %02d; C/M/P/L classes
 *      kept whole.
 *   #7 the [C,M,P,L] character class contains a literal comma and is
 *      unanchored — preserved verbatim, do NOT "fix" without a ledger entry.
 *   #8 decision table: reached = count >= limit; excepted styles swap in the
 *      exception limit; EMPTY exception limit = unlimited.
 *   #9 comp-limit flags only clear the final $process_allowed_entries 403
 *      gate — the msg=8/msg=9 cap redirects run BEFORE it, unconditionally.
 *   #10 admins (userLevel <= 1) bypass all caps; non-owner entrants denied
 *      at the same 403 gate (after the cap checks).
 *
 * Deliberately DB-free: counts and prefs are passed in so the decision table
 * is unit-testable (the wiring supplies values from preferences/brewing).
 */
final class EntryLimits
{
    public const REASON_USER_CAP = '8';

    public const REASON_SUBCATEGORY_CAP = '9';

    public const REASON_NOT_OWNER = '403';

    public const REASON_STYLE_CAP = '12';

    /**
     * @param  string  $action  'add' or 'edit'
     * @param  int  $userLevel  legacy session userLevel (<=1 admin, 2 entrant)
     * @param  bool  $ownsEntry  submitted brewBrewerID matches the user's id
     * @param  bool  $entryLimitEnabled  legacy comp_entry_limit flag
     * @param  bool  $paidLimitEnabled  legacy comp_paid_entry_limit flag
     * @param  ?string  $userEntryLimit  prefsUserEntryLimit (''/null = none)
     * @param  int  $userEntryCount  COUNT(*) of ALL the user's brewing rows (#1)
     * @param  string  $style  "category-subcategory" (e.g. "28A", "M1-1")
     * @param  ?string  $previousStyle  style before this edit (null = changed)
     * @param  bool  $editWindowOpen  entry window open at time of edit
     * @param  ?string  $subCatLimit  prefsUserSubCatLimit (''/null = none)
     * @param  string  $exceptionSubNum  prefsUSCLExLimit ('' = unlimited)
     * @param  string  $exceptionSubList  prefsUSCLEx, comma-separated style IDs
     * @param  int|string|null  $styleId  styles-table id of the chosen style
     * @param  int  $subCategoryCount  subcat count per #3/#4 filters
     */
    public static function check(
        string $action,
        int $userLevel,
        bool $ownsEntry,
        bool $entryLimitEnabled,
        bool $paidLimitEnabled,
        ?string $userEntryLimit,
        int $userEntryCount,
        string $style,
        ?string $previousStyle,
        bool $editWindowOpen,
        ?string $subCatLimit,
        string $exceptionSubNum,
        string $exceptionSubList,
        int|string|null $styleId,
        int $subCategoryCount,
    ): EntryLimitResult {
        // Legacy order (process_brewing.inc.php:42-84): the msg=8/msg=9
        // cap redirects run BEFORE the $process_allowed_entries 403 gate —
        // the comp-limit flags (#9) do NOT bypass cap enforcement, they only
        // clear the final ownership/admin kill switch.

        if ($userLevel === 2) {
            // #1/#2: per-user total cap on add ⇒ msg=8.
            if (
                $action === 'add'
                && $userEntryLimit !== null && $userEntryLimit !== ''
                && $userEntryCount >= (int) $userEntryLimit
            ) {
                return new EntryLimitResult(false, self::REASON_USER_CAP);
            }

            // #3–#5/#8: subcategory limit ⇒ msg=9. On edit only when the
            // window is open AND the style actually changed.
            if ($subCatLimit !== null && $subCatLimit !== '') {
                $styleChanged = $action === 'add'
                    || $previousStyle === null
                    || $style !== $previousStyle;

                if (($action === 'add' || ($action === 'edit' && $editWindowOpen && $styleChanged))
                    && self::subcategoryReached($style, $subCatLimit, $exceptionSubNum, $exceptionSubList, $styleId, $subCategoryCount)
                ) {
                    return new EntryLimitResult(false, self::REASON_SUBCATEGORY_CAP);
                }
            }
        }

        // #9/#10: final 403 gate. Allowed when admin, or a comp-level limit
        // flag is disabled, or the entrant submitted under their own ID;
        // otherwise legacy destroys the session and bounces to 403.
        $allowed = $userLevel <= 1
            || ! $entryLimitEnabled
            || ! $paidLimitEnabled
            || $ownsEntry;

        return new EntryLimitResult($allowed, $allowed ? '' : self::REASON_NOT_OWNER);
    }

    /**
     * Per-participant capacity caps beyond the user/subcat pair — the
     * Entries tab's "Entry Limits by Style or Table/Medal Group" grid
     * (prefsStyleLimits), its per-style-type limits
     * (style_types.styleTypeEntryLimit) and its per-table limits
     * (judging_tables.tableEntryLimit). Every limit/count pair is resolved
     * by the caller; a null limit means "not configured" and is skipped.
     * Same decision rule as the user/subcat caps: reached when the existing
     * count is already >= the limit. Admins bypass all caps.
     */
    public static function checkCapacity(
        int $userLevel,
        ?int $groupLimit,
        int $groupCount,
        ?int $styleTypeLimit,
        int $styleTypeCount,
        ?int $tableLimit,
        int $tableCount,
    ): EntryLimitResult {
        if ($userLevel !== 2) {
            return new EntryLimitResult(true, '');
        }

        foreach ([[$groupLimit, $groupCount], [$styleTypeLimit, $styleTypeCount], [$tableLimit, $tableCount]] as [$limit, $count]) {
            if ($limit !== null && $limit > 0 && $count >= $limit) {
                return new EntryLimitResult(false, self::REASON_STYLE_CAP);
            }
        }

        return new EntryLimitResult(true, '');
    }

    /**
     * Effective per-participant cap from the #1-#4 incremental tiers
     * (prefsUserEntryLimitDates): the limit-number of the first tier whose
     * window — limit-days after the entry window opened — has not yet
     * expired. Null when no window applies (or none is configured), meaning
     * "no incremental limit". The overall prefsUserEntryLimit still wins
     * when it is lower (the caller takes the minimum).
     *
     * @param  array<int|string, mixed>  $tiers  decoded prefsUserEntryLimitDates
     */
    public static function incrementalLimit(array $tiers, ?int $entryOpenEpoch, int $now): ?int
    {
        if ($entryOpenEpoch === null || $entryOpenEpoch < 1) {
            return null;
        }

        for ($i = 1; $i <= 4; $i++) {
            $tier = $tiers[$i] ?? $tiers[(string) $i] ?? null;
            if (! is_array($tier)) {
                continue;
            }

            $number = (int) ($tier['limit-number'] ?? 0);
            $days = (int) ($tier['limit-days'] ?? 0);
            if ($number < 1 || $days < 1) {
                continue;
            }

            if ($now < $entryOpenEpoch + ($days * 86400)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * Decision tail of legacy limit_subcategory (common.lib.php:3671-3680):
     * reached = count >= limit; excepted styles swap in the exception limit;
     * EMPTY exception limit = unlimited (#8).
     */
    private static function subcategoryReached(
        string $style,
        string $subCatLimit,
        string $exceptionSubNum,
        string $exceptionSubList,
        int|string|null $styleId,
        int $count,
    ): bool {
        if ($style === '') {
            return false;
        }

        $reached = $count >= (int) $subCatLimit;

        if ($reached && $exceptionSubList !== '' && $styleId !== null
            && in_array((string) $styleId, explode(',', $exceptionSubList), true)
        ) {
            return $exceptionSubNum !== '' && $count >= (int) $exceptionSubNum;
        }

        return $reached;
    }

    /**
     * WHERE-shape for the subcategory count query (#3/#4): exact equality on
     * brewBrewerID + brewCategorySort + brewSubCategory, but the BA style set
     * DROPS the category filter (categorySort null).
     *
     * @return array{categorySort: string|null, subCategory: string}
     */
    public static function countFilters(string $style, bool $baStyleSet): array
    {
        $parts = explode('-', $style);

        return [
            'categorySort' => $baStyleSet ? null : self::normalizeCategory($parts[0] ?? ''),
            'subCategory' => $parts[1] ?? '',
        ];
    }

    /**
     * Category normalization (#6/#7): numeric ≤9 zero-padded %02d; anything
     * matching /[C,M,P,L]/ kept WHOLE (unanchored pattern, literal comma —
     * preserved verbatim from common.lib.php:3630-3634).
     */
    public static function normalizeCategory(string $category): string
    {
        if (preg_match('/[C,M,P,L]/', $category)) {
            return $category;
        }

        if ($category <= 9) {
            return sprintf('%02d', $category);
        }

        return $category;
    }
}
