# Behavior Ledger: contest_info & preferences

Status: characterization complete (P1.1). Pinning tests live in
`tests/Characterization/ContestInfoTest.php` unless noted "source-read".

## Purpose

The `contest_info` row (id=1) configures one competition iteration — identity,
date windows, entry fees, shipping/drop-off logistics. The `preferences` row
(id=1) configures the installation — payment methods, entry limits, style set,
locale, winner visibility. Both rows are copied wholesale into `$_SESSION` and
read by nearly every page.

## Inputs

- Tables touched: `contest_info`, `preferences` (+ `judging_preferences` loaded
  alongside prefs; covered by its own ticket).
- Session/state dependencies:
  - Loader (`includes/db/common.db.php:63-114`, source-read): every column of
    the row is copied into `$_SESSION[$column]` except `id`;
    `$_SESSION['comp_id']` = row id. Cached behind
    `$_SESSION['contest_info_general'.$prefix_session]` (contest_info) and
    `$_SESSION['prefs'.$prefix_session]` (preferences + judging_preferences).
  - Cache invalidation on save: `process_prefs.inc.php:837`,
    `process_styles.inc.php:104,269`, `process_judging_preferences.inc.php:45`
    unset the prefs flag; `process_comp_info.inc.php:252` (update path only)
    unsets the contest_info flag; `process.inc.php:240-241` unsets both on
    logout/archive. **A pref/contest-info change takes effect on the next
    request after save**, never mid-request.
  - Setup-step pages (`section` contains "step") never populate these caches;
    step4 reads fresh rows instead.

## Pinned behaviors

### Window state machine — `open_or_closed($now, $open, $close)` → 0|1|2

| # | Behavior | Pinning test | Notes / edge cases |
|---|----------|--------------|--------------------|
| 1 | Returns 0 before `$open`, 1 from `$open` (inclusive) until but excluding `$close`, 2 strictly after `$close` | `test_window_state_*` | The single primitive behind every window flag; consumers branch on `== 1` / `== 2` / `< 2`. |
| 2 | At `$now == $close` exactly, returns **0** (not-yet-open), not 2 | `test_weirdness_window_at_the_exact_close_instant_reports_not_yet_open` | Legacy off-by-one gap: neither `< $date2` nor `> $date2` matches. Preserve or file as approved deviation in the port. |
| 3 | Either date NULL/unset → 0 | `test_missing_dates_report_the_window_as_closed_before_open` | Indistinguishable from "not open yet". |

### Column → window mapping (constants.inc.php:163-169, 226, source-read)

| State var | Derived from |
|-----------|--------------|
| `$registration_open` | `open_or_closed(now, contestRegistrationOpen, contestRegistrationDeadline)` |
| `$entry_window_open` | `open_or_closed(now, contestEntryOpen, contestEntryDeadline)` |
| `$judge_window_open` | `open_or_closed(now, contestJudgeOpen, contestJudgeDeadline)` |
| `$dropoff_window_open` | both `contestDropoffOpen`+`contestDropoffDeadline` non-empty → open_or_closed; **else defaults to 1 (OPEN)** |
| `$shipping_window_open` | same pattern over `contestShippingOpen`/`contestShippingDeadline`; **else 1 (OPEN)** |
| `$pay_window_open` | `open_or_closed(now, contestEntryOpen, last judging-session date)` |
| override | once any judging session has started (`time() > min(judgingDate)`): `$entry_window_open = $registration_open = 2` regardless of dates |
| forced close | `$entry_window_open = 2` when `prefsEntryLimit`/`prefsEntryLimitPaid` reached (see #12); judge/steward caps force `$judge_window_open = 2` via `open_limit()` |
| `$disable_pay` | TRUE only when registration, shipping, dropoff, entry AND pay windows are all 2 |

Display strings for each window come from
`getTimeZoneDateTime($_SESSION['prefsTimeZone'], <epoch>, prefsDateFormat,
prefsTimeFormat, …)` — the epoch round-trip contract is pinned by
`tests/Integration/TimeZoneEpochTest.php`.

### Winner visibility & limits

| # | Behavior | Pinning test | Notes / edge cases |
|---|----------|--------------|--------------------|
| 4 | Winner presentation shows when `prefsDisplayWinners == "Y"` AND `judging_winner_display(prefsWinnerDelay)`; scores/scoresheets additionally require login; whole block skipped when `$judging_past != 0` | `test_winner_display_requires_time_strictly_past_delay` (delay predicate only) | `judging_winner_display($d)` = `time() > $d` (strict). Combination logic is constants.inc.php:528-536, source-read. |
| 5 | `open_limit(total, limit, windowState)`: false when limit empty/'' ; else `(total >= limit) && (windowState == "1")` | `test_capacity_limit_closes_only_when_limit_reached_while_window_open` | Loose `"1"` comparison — int window state 1 passes. Used for judge/steward caps and entry-limit flips. |

### Fee model — `total_fees()` / `total_fees_paid()` (legacy/common.lib.php)

Arguments map to session keys copied from contest_info:
`$entry_fee`=contestEntryFee, `$entry_fee_discount`=contestEntryFee2 (volume
rate), `$entry_discount`=contestEntryFeeDiscount (Y/N),
`$entry_discount_number`=contestEntryFeeDiscountNum (threshold),
`$cap_no`=contestEntryCap (''/0 = none), `$special_discount_number`=
contestEntryFeePasswordNum (member rate). Fees/threshold/cap arrive as STRINGS.
All values below verified against the vendored function bodies
(scratch harness `/tmp/check_fees.php`, plus MySQL-gated tests).

| # | Behavior | Pinning test | Notes / edge cases |
|---|----------|--------------|--------------------|
| 6 | Per-brewer due = confirmed entries × base fee | `test_per_brewer_fee_is_confirmed_entries_times_base_fee` | bid≠default branch filters `brewConfirmed='1'`. |
| 7 | Volume discount boundary: entries ≤ threshold pay base fee for ALL; entries > threshold pay `threshold × fee + remainder × fee2` | `test_volume_discount_boundary_first_n_full_then_discounted_rate` | N=2→16, N=3→21, N=4→26 with fee 8/disc 5/threshold 2. |
| 8 | Cap clamps per-brewer total: `> 0` clamps at cap; `''` and `'0'` mean no cap; total below cap unchanged | `test_cap_clamps_the_per_brewer_total_and_empty_or_zero_means_no_cap` | Loose `> 0` on string cap. |
| 9 | Member discount (`brewerDiscount='Y'` on brewer row + password num set): flat N × member rate; volume settings ignored when discount flag N | `test_member_discount_flat_rate_applies_when_set_and_standard_math_when_blank` | brewerDiscount is set per-brewer at signup by entering `contestEntryFeePassword` (process.inc.php:271-279). |
| 10 | Weirdness: `brewerDiscount='Y'` with EMPTY password num falls through to standard full-price math | `test_member_discount_flat_rate_applies_when_set_and_standard_math_when_blank` | Member flag without rate ⇒ entrant pays full price. |
| 11 | Member + volume combined: first `threshold` entries bill member rate; remainder bill **min(fee2, member rate)** | `test_member_plus_volume_discount_charges_the_cheaper_rate_on_excess_entries` | 2×6+2×5=22 (fee2 cheaper); 2×7+2×5=24 (volume cheaper). Entrant always gets the better excess rate. |
| 12 | Zero entries → 0.0; return type float; totals displayed via `number_format(x,2)` half-up | `test_brewer_with_no_entries_costs_nothing`, `test_fees_are_displayed_via_half_up_rounding_to_two_decimals` | Rounding happens at display only; stored/computed amounts are exact floats. |
| 13 | Weirdness: `total_fees_paid()` does NOT filter `brewConfirmed`, so paid can exceed due with paid-but-unconfirmed entries | `test_weirdness_paid_totals_ignore_confirmation_so_paid_can_exceed_due` | due 16 vs paid 24 in fixture; `$total_to_pay = due − paid` goes negative. |
| 14 | Paid tiering mirrors due side: partial payment past threshold bills `threshold × fee + (paid − threshold) × fee2`; fully-paid uses TOTAL entries (not paid count) for the remainder | `test_paid_fees_tier_through_the_volume_discount_like_the_due_side` | 3of4→21; 4of4→26. |
| 15 | Weirdness: aggregate view (`bid='default', filter='default'`) sums ALL users AND counts UNCONFIRMED entries — inconsistent with per-brewer branch | `test_weirdness_aggregate_default_view_sums_all_users_and_counts_unconfirmed_entries` | Admin dashboard totals can exceed the sum of per-entrant dues. |

### Preferences consumed by Phase-2/3 pages (source-read classification)

| # | Preference(s) | Classification | Where it gates behavior |
|---|---------------|----------------|-------------------------|
| 16 | `prefsPaypal`, `prefsPaypalAccount`, `prefsPaypalIPN` | gating | PayPal section on pay page + "pay now" copy only when `== "Y"` (pay.sec.php/pub:122-260); account required in prefs UI when enabled. |
| 17 | `prefsCash`, `prefsCheck`, `prefsCheckPayee` | gating (UI) | Cash/check sections on pay page; pay-page anchor link shown only if any of cash/check/paypal is Y and `contestEntryFee > 0` (entry_info.pub.php:316). |
| 18 | `prefsTransFee` | gating (display) | Adds PayPal fixed-fee footnote keyed by currency symbol ($0.49 etc., pay.sec.php:154-223). |
| 19 | `prefsPayToPrint` | gating | `pay_to_print(prefsPayToPrint, brewPaid)` (lib/output.lib.php:139-148, source-read): labels printable iff (Y ∧ paid=1) or pref=N. Also drives unpaid-entry warnings. NOT vendored yet — port target for P2.x. |
| 20 | `prefsEntryLimit`, `prefsEntryLimitPaid` | gating | Comp-wide entry/paid caps flip `$entry_window_open = 2`; near-limit warning at 90% of limit (constants.inc.php:430-458). |
| 21 | `prefsUserEntryLimit`, incremental limits (`limit-number`/`limit-days`) | gating | Per-user remaining entries; incremental tiers measured from `contestEntryOpen + days × 86400` (common.db.php:432-453). |
| 22 | `prefsUSCLEx`, `prefsUSCLExLimit`, `prefsUserSubCatLimit`, `prefsStyleLimits` | gating | Subcategory entry limits incl. excepted subcategories (`limit_subcategory()`, common.lib.php:4343). Covered further by scoring/limits tickets. |
| 23 | `prefsStyleSet` | gating | Selects style universe (styles.db.php default BJCP2025; BA special-case queries DB); drives specialty IPA/historical subcategory handling. |
| 24 | `prefsLanguage` | gating (locale) | Folder mapping; weirdness preserved: value "english" rewritten to "en-US" (common.db.php:368). `en-*` also switches sidebar date format to long (constants.inc.php:266). |
| 25 | `prefsTimeZone` (+`prefsDateFormat`, `prefsTimeFormat`) | gating (dates) | Every date render and every `to_utc_epoch()` conversion; round-trip contract pinned in TimeZoneEpochTest. |
| 26 | `prefsWinnerDelay`, `prefsDisplayWinners` | gating | See #4. |
| 27 | `prefsEmailSMTP`/Host/From/Username/Password/Port/Encrypt, `prefsEmailRegConfirm`, `prefsEmailCC` | gating (mail) | SMTP used only when SMTP=1 and all host/from/user/pass/port non-empty (process.inc.php:34-35). |
| 28 | `prefsCurrency` | gating (display) | Currency symbol; keys the PayPal fixed-fee table. |
| 29 | `prefsSponsors`, `prefsSponsorLogos` | gating (display) | Sponsor nav/homepage sections shown only when `== "Y"` AND sponsor count > 0 (nav.sec.php:65). |
| 30 | `prefsRecordPaging` | behavior | DataTables page size = `round(prefsRecordPaging)` (constants.inc.php:542). |
| 31 | `prefsTheme` | display | Deprecated-theme failsafe is a NO-OP bug (see weirdness W3). |
| 32 | `prefsCAPTCHA`/`prefsGoogleAccount` | gating (forms) | Captcha rendered on public forms when enabled. |
| 33 | `prefsShowBestBrewer`/`prefsBestBrewerTitle`/place points/tie-breakers, `prefsShowBestClub`, `prefsScoringCOA`, `prefsWinnerMethod` | gating (scoring/display) | Scoring semantics owned by the scoring tickets; BestBrewerPointsTest pins the COA method. |

## Deliberate weirdness (preserve or file approved deviations)

- **W1 — close-instant gap**: `open_or_closed(now==close)` returns 0
  ("before open"), not 2 (#2).
- **W2 — empty dropoff/shipping windows default OPEN** (state 1) when either
  date is blank — a competition with no drop-off dates never closes that
  window.
- **W3 — `prefsTheme` failsafe no-op** (includes/constants.inc.php:38):
  `if (...claussenii||naardenensis) $_SESSION['prefsTheme'] == "default";`
  compares instead of assigns. Deprecated themes are NOT actually replaced.
  Port nothing (the check does nothing); do not "fix" silently.
- **W4 — paid vs due confirmation asymmetry** (#13/#15): aggregate counts
  unconfirmed, per-brewer excludes them, paid side ignores confirmation
  entirely → negative balances possible.
- **W5 — "english" language value rewritten to "en-US"** at load time (#24).
- **W6 — member flag without rate pays full price** (#10).
- **W7 — BJCP ID auto-wipe**: 60 days after the latest deadline/judging/
  awards epoch, `contestID` is NULLed IN THE DATABASE on page load
  (constants.inc.php:236-250) and XML report generation is blocked while
  empty (admin/default.admin.php:2977).
- **W8 — `contestCheckInPassword`** is hashed on save
  (process_comp_info.inc.php:230-234) and gates barcode check-in;
  `contestEntryFeePassword` is stored encrypted (`simpleDecrypt`,
  server-derived key — see ledger crypto.md) and compared at signup to grant
  `brewerDiscount='Y'`.
- **W9 — setup-step pages bypass the caches entirely** and read rows fresh;
  the initial contest_info INSERT during setup does not unset the cache flag
  (it was never set on step pages).

## Not pinned, do not port logic for

Pure display columns (rendered into pages, no branching): `contestName`,
`contestHost`, `contestHostWebsite`, `contestHostLocation`,
`contestAwardsLocation`, `contestAwardsLocName`, `contestShippingName`,
`contestShippingAddress`, `contestLogo`, `contestBottles`, `contestRules`
(JSON blob: competition_rules/packing_shipping/markdown handling),
`contestCircuit`, `contestVolunteers` (volunteers page body),
`contestBOSAward`, `contestAwards` (text areas), `contestWinnerLink`
(displayed link), `contestClubs` (JSON list → club array for display +
entrant club pick), `contestDropoffOpen`-adjacent drop-off *location* rows
(own table/ticket).

Preferences referenced by legacy code but with no Phase-2/3 gating role found
beyond display/config plumbing — port as opaque settings, no logic:
`prefsProEdition`, `prefsSpecific`, `prefsMHPDisplay`, `prefsLiquid2` (only
referenced in archive session-copy code paths), `prefsEntryForm`,
`prefsContact`, `prefsCompLogoSize`, `prefsSpecialCharLimit`,
`prefsAutoPurge`, `prefsDisplaySpecial`, `prefsBOSMead`, `prefsBOSCider`
(BOS eligibility flags — classify precisely in the BOS ticket),
`prefsHideRecipe`, `prefsUseMods`, `prefsSEF`, `prefsSelectedStyles`,
`prefsGoogleAccount` (captcha key only), `prefsSponsorLogos` sizing fields.

`contestAwardsLocDate`/`contestAwardsLocTime` feed the `$later_date_arr`
60-day wipe clock (W7) and award-night display — semi-gating; revisit in the
awards/XML ticket before porting.

## Slice A landing composition (pinned 2026-08-24, parity gate)

Sources: `index.pub.php` (section assembly), `pub/default.pub.php`
(at-a-glance state machine), `pub/judge_closed.pub.php` (blurb),
`pub/nav.pub.php` (nav), `includes/constants_post_lang.inc.php`
(archive gate). Verified zero-diff against legacy on anon-base,
synth-100-winners-shown, and the CI baseline dump.

| # | Behavior | Notes |
|---|----------|-------|
| L1 | Nav links: Rules + Volunteers only before judging starts (`time() > first judgingDate`); Entry Info only while `judging_past > 0` (future sessions remain); Sponsors when `prefsSponsors=Y` and sponsor rows exist; Contact always | `judging_past` is legacy's name for "future sessions remain" — inverted naming (`judging_date_return()` counts sessions with `judgingDate >= now`) |
| L2 | Judge-closed blurb ("Thanks to all who participated… There were N entries judged and M registered participants…") renders whenever registration + entry are closed AND no future judging session remains — in EVERY winner-display state, including alongside the results block | Counts: N = received brewing rows (`brewReceived=1`), M = brewer rows (amateur edition; pro edition swaps N for participant count only) |
| L3 | Results replace the at-a-glance cards only when all-closed AND `prefsDisplayWinners=Y` AND `now > prefsWinnerDelay`; when `Y` but delay not passed, cards + winner-delay announcement render; when `N`, blurb only | `$show_at_a_glance` false in results path; true in the delay path |
| L4 | Landing salutation ("Thank you for your interest in…") renders in the header after the hero, before the print-only h1 | DOM order: hero, salutation, print-h1, at-a-glance |
| L5 | `past-winners/{suffix}`: unknown/undisplayable archive (missing row, `archiveDisplayWinners!=Y`, empty `archiveStyleSet`, absent `brewer_/brewing_/judging_scores_<suffix>` tables, or zero archived scores) → 302 to `/?msg=8` → landing alert "Archived data is not available." | Matches `constants_post_lang.inc.php` |
| L6 | `/list` anonymous → 302 to `/?msg=99` → landing alert "Please log in to access your account." | Matches legacy `list.pub.php` |
| L7 | Section alerts render between nav and hero | `alerts.pub.php` placement |
| L8 | Login-modal chrome (`Log In`, `Password`, `Reset Password`…) is normalized away in the parity harness, not ported (anonymous slice) | `tools/parity/chrome-exclude.txt` |

Not exercised by the gate (no corpus dump in that state): winner-delay
announcement rendering (L3 mid-state), displayable-archive past-winners
surface, sponsors with rows present, "Other Info" nav link.
