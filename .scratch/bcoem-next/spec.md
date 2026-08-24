# Spec: bcoem-next — full Laravel port of BCOE&M

Status: draft
Parent context: modernization branch (61/100), hosted multi-tenant business plan.
Constraint: this stream is NEVER live until it passes the Parity Gate (§8). The
production fork continues independently; nothing here blocks tenant operations.

## 0. Decisions (locked)

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Framework: Laravel latest, PHP 8.5 | Same language; ops plan unchanged; best AI-training corpus |
| D2 | Reuse existing MySQL schema verbatim (`sql/bcoem_baseline_3.0.X.sql`, 24 tables) | Real tenant dumps load directly → free test corpus + zero-migration cutover path |
| D3 | Legacy app is the executable spec; behavior captured by characterization tests + ledger, not by reading code | Undocumented domain logic (BOS rounding, timezone epochs, entity cleanup) must be pinned before rewrite touches it |
| D4 | `src/` typed layer ports near-verbatim as first models/services | Already strict_types, tested, schema-exact |
| D5 | Auth = Laravel starter kit; phpass-era sessions not ported | One-way door taken early |
| D6 | Parity harness built in Phase 0, before any feature code | Output-diff safety net governs every later phase |
| D7 | Payments behind a **gateway adapter** with two first-class implementations: **Stripe Connect** (online; tenants self-onboard as Standard connected accounts, funds never touch the platform) and **manual marking** (admin confirms check/cash/dropoff/bank-transfer). Modern PayPal (Checkout SDK + webhooks) and Square are future adapters if tenant demand proves it. PayPal/IPN is NOT ported — it is EOL Jan 2027 and new IPN credentials stopped being issued end of 2025 | User decision revised 2026-08-24. Webhook/manual-driven state changes (paid/confirmed flags) replace IPN. Staying on legacy PayPal would itself be a regression |

## 1. Surface inventory (the complete port backlog)

Legacy surface to cover — every unit below is a ticket or part of one:

- **Public pages** (`pub/`, 46): register, login, pay, brewer forms 0/1/2,
  brewer_info, brewer_entries, entry_info, brew, judge, judge_info,
  judge_closed, reg_closed/reg_open, list, winners (+category/subcategory,
  past_winners), bos, bestbrewer, at-a-glance, alerts, contact, rules,
  sponsors, nav/footer/sidebar, electronic_scoresheets, eval_* (10),
  admin-nav/admin (organizer view of public pages)
- **Admin pages** (`admin/`, 35): competition_info, site_preferences,
  all_dates, entries (+by_style/by_substyle), participants, payments,
  judging_preferences, judging_locations, non-judging_locations, dropoff,
  judging_tables, judging_flights, judging_assign, judging_scores,
  judging_scores_bos, special_best(+data), sponsors, contacts, mods,
  style_types, styles mgmt, archive, barcode_check-in, make_admin,
  change_user_password, send_test_email, hero_images, sidebar(s)
- **Evaluation app** (`eval/`, 24): separate scoresheet UI (dashboard, full +
  structured variants incl. NW cider, checklist, import_scores, my_account,
  warnings, process) — treat as a sub-application with its own route prefix
- **Generated outputs** (`output/`, 21): pullsheets, labels, table_cards,
  bottle_label, shipping_label, entry, participant_summary,
  participant_entries_list, post_judge_inventory, judge_notes, assignments,
  sorting, staff_points, styles, maps, dropoff, print, results,
  bos_mat, export, scoresheets
- **AJAX endpoints** (`ajax/`, 11): save, regenerate, purge, count_records,
  account_checks, username, valid_email, custom_style, tables_mode,
  import_scores, practice_session
- **Background behaviors**: email sending (registration confirm, pay
  confirm, admin notify), archive/competition-close flow, data purge

## 2. Phase 0 — Foundations (no features)

Tickets:
- [ ] P0.1 Create repo `bcoem-next`; Laravel skeleton; PHP 8.5; `strict_types` everywhere from commit one.
- [ ] P0.2 Load baseline SQL into local MySQL 8; point Eloquent at it WITHOUT migrating schema (D2). Document every table/column mismatch Laravel chokes on.
- [ ] P0.3 Port `src/Connection.php`, all 24 repositories + row classes nearly verbatim; adapt namespaces only. Existing unit/integration tests come along and must pass.
- [ ] P0.4 CI: PHPUnit + PHPStan max level + Laravel pint. Baseline policy: starts empty, stays empty.
- [ ] P0.5 Tenant dump corpus: collect ≥5 anonymized real dumps spanning style sets (BJCP2021/2025, AABC2025, cider cup), languages, archived comps. Store locally, never in repo.
- [ ] P0.6 **Parity harness v1**: script loads a dump → boots legacy app (PHP built-in server) and new app side by side → GETs a URL list → normalizes (strip csrf/tokens/sessions/nonces) → diffs HTML. Report = pass/fail per URL.
- [ ] P0.7 Behavior-ledger template + `.scratch/bcoem-next/ledger/<module>.md` scaffold.

Acceptance: parity harness runs green on login page (trivially identical),
CI red-green proven, corpus loads.

## 3. Phase 1 — Behavior capture (runs alongside early build phases)

For each module family, produce: characterization tests (legacy) + ledger page.
Order matches build order so no phase waits on the whole phase.

- [ ] P1.1 Contest info & preferences semantics (contest_info, preferences rows)
- [ ] P1.2 Registration eligibility rules (reg_open/closed, entry limits, style limits, paid/unpaid)
- [ ] P1.3 Entry lifecycle (brewing row states, received/paid/confirmed flags, judging numbers)
- [ ] P1.4 Payments data model: characterize the `payments` table semantics and the entry paid/confirmed flag lifecycle (what the legacy PayPal flow wrote, and when). The data contract carries over; all transports are new (D7). Ledger must record which state transitions were IPN-driven so webhooks/manual marking can reproduce them.
- [ ] P1.5 Flights/tables/assignments algorithms (rounding, table caps, judge prefs)
- [ ] P1.6 Scoring & BOS (score placement strings, BOS round logic, best-brewer points)
- [ ] P1.7 Winners display rules (winner delay timestamps, category collapse)
- [ ] P1.8 Styles system (style sets, custom categories via mods, style_types)
- [ ] P1.9 Eval sub-app semantics (scoresheet variants, import format)
- [ ] P1.10 Archive/purge flows

Acceptance per ticket: tests green against legacy; ledger page reviewed;
both committed before the corresponding build slice starts.

## 4. Phase 2 — Slice A: read-only public surface (lowest risk first)

Goal: prove the parity harness on real pages with zero auth complexity.

- [x] P2.1 Routing/layout shell: nav/footer/sidebar as Blade components driven by preferences rows
- [x] P2.2 `default`, `rules`, `contact`, `sponsors`, `alerts`
- [x] P2.3 `list` (entries browser) + `at-a-glance`
- [x] P2.4 `winners`, `winners_category`, `winners_subcategory`, `past_winners`, `bos`, `bestbrewer`
- [x] P2.5 `reg_closed`/`reg_open` states
- [x] P2.6 Parity run: all above URLs × corpus dumps; fix diffs to threshold

Acceptance: parity report shows ≤ cosmetic diffs on these routes for every dump.

## 5. Phase 3 — Slice B: accounts + registration (revenue path)

- [ ] P3.1 Auth: register/login/reset on users table (Laravel starter kit adapted to legacy columns; password rehash-on-login for phpass hashes)
- [ ] P3.2 Brewer profile (brewer_info, brewer_form_0/1/2 wizard, clubs, AHA/BJCP fields)
- [ ] P3.3 Entry creation/edit (brew, brewer_entries, entry_info; category/style pickers incl. custom categories)
- [ ] P3.4 Entry limits engine (P1.2 rules implemented + unit-tested)
- [ ] P3.5a Gateway adapter interface: single contract (create checkout/session for an entry batch, verify/receive state callbacks, refund/cancel hooks) so providers are one-class swaps.
- [ ] P3.5b Stripe Connect implementation: Stripe Checkout session per entry batch against the tenant's connected account; signature-verified webhook endpoint writes `payments` rows and flips entry paid/confirmed flags idempotently; tenant onboarding = Connect Standard OAuth flow; platform webhook secret per tenant stored in tenant config. Tested with Stripe test mode + mocked events — NOT parity-diffed (external side effects).
- [ ] P3.5c Manual payments: admin "mark paid" (with method/reference note + audit trail) covering check-by-mail, cash/check at dropoff, bank transfer; same `payments` rows and flag lifecycle as the Stripe path so downstream code is transport-blind.
- [ ] P3.6 Confirmation emails (mail templates ported; SMTP via Laravel mailer)
- [ ] P3.7 AJAX endpoints needed by this slice: username, valid_email, account_checks, save, count_records
- [ ] P3.8 Parity + end-to-end test: full fake registration on a corpus dump

Acceptance: a volunteer can register, enter, edit entries on staging copy of a
real dump with zero console errors; parity diffs explained or fixed.

## 6. Phase 4 — Slice C: judging (highest complexity)

Depends on P1.4–P1.6 ledgers.

- [ ] P4.1 Admin judging config: locations, non-judging locations, dropoff, tables, flights, preferences
- [ ] P4.2 Flight/table assignment engine (port algorithm behind its tests)
- [ ] P4.3 Judging assignments UI (judge/steward assignment, availability)
- [ ] P4.4 Score entry (judging_scores) + BOS (judging_scores_bos) + special_best
- [ ] P4.5 Barcode check-in flow
- [ ] P4.6 Eval sub-app under `/eval` route prefix (scoresheet variants, checklist, import_scores, practice_session)
- [ ] P4.7 AJAX: tables_mode, import_scores, practice_session, custom_style
- [ ] P4.8 Parity + scripted season simulation (assign → score → BOS on corpus dump)

## 7. Phase 5 — Slice D: outputs & admin remainder

- [ ] P5.1 PDF pipeline decision: replace fpdf with dompdf/snappy; one output ported first (pullsheets) to validate approach
- [ ] P5.2 Port remaining outputs in pairs (labels+bottle_label, table_cards+sorting, shipping_label, entry, participant_summary, participant_entries_list, post_judge_inventory, judge_notes, assignments, staff_points, styles, maps, dropoff, print, results, bos_mat)
- [ ] P5.3 Export (CSV/XLSX) — verify column-for-column vs legacy
- [ ] P5.4 Remaining admin: competition_info, site_preferences, all_dates, hero_images, sponsors, contacts, mods, style_types, styles, make_admin, change_user_password, send_test_email
- [ ] P5.5 Participants/payments/entries admin views (+by_style/by_substyle reports)
- [ ] P5.6 Archive + purge admin flows (P1.10)

## 8. Parity Gate (graduation test — the only path to "live")

For every corpus dump, on identical DB copies:

1. Both apps serve the full URL inventory; normalized diffs below agreed threshold (cosmetic-only).
2. All characterization + new-app tests pass.
3. Full simulated season: register → pay → assign → score → BOS → results → outputs, executed identically on both apps; generated artifacts byte-compared where deterministic (CSV exports), visually approved where not (PDFs).
   - Payment step exception (D7): the "pay" leg is validated by resulting DB state, not page diffing — new app uses Stripe test mode / manual marking, legacy uses its IPN sandbox or manual marking; both must converge to identical `payments` + entry-flag rows.
4. Security review: no sprintf-SQL anywhere (Laravel ORM only), auth coverage on every admin/eval route, upload validation on user_images/docs equivalents.
5. Performance smoke: 500-entry dump serves key pages < 300ms p95.

Pass → propose cutover pilot for ONE willing tenant. Fail/stall → stream stays
sandboxed; production fork unaffected. Either outcome is acceptable.

## 9. Explicitly NOT ported

- HOSTED/SINGLE mode remnants, sso/ dir, NHC flag
- Deprecated themes (claussenii, naardenensis)
- Legacy PayPal/IPN transport (D7): EOL Jan 2027, unreplacable for new tenants. Only the `payments` data-model semantics carry over; modern-PayPal and Square may return as future adapters if demand proves it
- phpass, MysqliDb, dbObject, tiny_but_strong, markdownify, is_email, qr_code
  hand-rolled libs (replaced by Laravel/native equivalents)
- update/*.php migration history (fresh install baseline only)

## 10. Cadence & tracking

- Tickets minted from this spec into `.scratch/bcoem-next/issues/NN-*.md` when their phase opens.
- Ledger lives in `.scratch/bcoem-next/ledger/`.
- Quarterly re-grade of production fork continues regardless; this stream does not pause it.
