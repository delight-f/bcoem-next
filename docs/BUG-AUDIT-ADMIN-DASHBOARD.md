# Bug audit — admin dashboard surface

Date: 2026-09-13
Scope: admin/back-office screens reachable from the admin dashboard, other than
`/admin/site-preferences` (already handled separately).
Method: static audit of `routes/admin.php`, every controller in
`app/Http/Controllers/Admin`, the matching `resources/views/admin` blades and the
support classes they call, cross-checked against the frozen `legacy/` reference
where a docblock claims parity. Each finding below was confirmed by reading the
offending code path; two PHP-semantics checks were run locally (noted inline).
Nothing in this document was produced by pattern-matching alone.

Confidence is stated per finding. "Verified" means the mechanism was read
end-to-end in source. Runtime reproduction of the high-severity items is still
worth doing before fixes land.

**Status update (2026-09-13).** All findings have been fixed except **D4**,
which the pinned parity test
`AdminScreensStylesTest::test_edit_renames_style_and_cascades_into_brewing`
shows is intended legacy behaviour — see that entry. The suite runs with zero
failures after the fixes (the 24 pre-existing Output-test error-handler errors
are unchanged from the baseline).

## Summary

| ID | Severity | Confidence | Area | One-line |
|---|---|---|---|---|
| A1 | High | Verified | Auth | Scoresheet upload has no admin gate |
| A2 | High | Verified | Auth | NULL `userLevel` counts as admin |
| A3 | High | Verified | Auth | Level-1 admin can self-promote / demote last top admin |
| A4 | High | Verified | Auth | Level-1 admin can reset a top admin's password |
| A5 | Medium | Verified | Auth | Top-admin guards fail open on null user |
| B1 | High | Verified | Data | Participant save 500s on any blank optional field |
| B2 | High | Verified | Data | Competition Info save wipes QR check-in password |
| B3 | High | Verified | Data | Blank PayPal secret wipes env-configured secret |
| B4 | High | Verified | Data | Shipped `bcoe` styles deletable / overwritable |
| B5 | Medium | Verified | Data | Payments ledger always records USD |
| B6 | Medium | Verified | Data | Sponsor logo cleared on an unrelated save |
| C1 | High | Verified | UI | Entries page nested forms break every action |
| C2 | High | Verified | UI | Styles page nested form breaks bulk update + delete |
| C3 | High | Verified | UI | Upload delete link is GET to a POST route |
| C4 | High | Verified | UI | Participants list 500s when anyone has a table |
| C5 | Medium | Verified | UI | Participant row icons point at the wrong target |
| D1 | Medium | Verified | Logic | Inverted ternary renders a 1970 judging-close date |
| D2 | Medium | Verified | Logic | Entry Status "Total Fees" ignores the fee model |
| D3 | Medium | Verified | Logic | Custom-style writer drops `brewStyleType` |
| D4 | Medium | Verified | Logic | Style-rename cascade trusts a client field |
| D5 | Medium | Verified | Logic | Sponsors bulk update skips validation |
| D6 | Medium | Verified | Logic | Sponsor logo list omits SVG the uploader accepts |
| D7 | Low | Verified | UI | Dangling `for=` on two Competition Info labels |
| D8 | Low | Verified | Logic | "Unpaid" filters exclude NULL `brewPaid` |
| D9 | Low | Verified | Logic | Delete/update report success for missing ids |
| D10 | Low | Verified | Logic | Dead emptiness guard on a Collection |

---

## A. Authorization

### A1 — Scoresheet upload has no admin gate — High

- **Where:** `app/Http/Controllers/Admin/UploadScoresheetsController.php:23`
  (`show`) and `:36` (`store`); route group `routes/admin.php:30`
  (`['web', 'auth']` only).
- **What:** neither action re-checks the admin gate. Every other admin
  controller in this directory does (`if (! ($request->user()?->isAdmin() ?? false)) return redirect('/?msg=99');`).
  This controller is the sole exception — it has zero `isAdmin` references.
- **Trigger:** any authenticated account (`userLevel` 2 entrant included) opens
  `/admin/upload-scoresheets` and posts `files[]`.
- **Impact:** a non-admin can list the scoresheet directory and upload arbitrary
  PDFs (up to 20 MB each) into the entrant-docs root, which is then streamed by
  the output endpoint. Authorization bypass, not just information disclosure.
- **Fix:** add the standard in-controller admin gate to both actions.

### A2 — NULL `userLevel` counts as admin — High

- **Where:** `app/Models/User.php:79` — `return (int) $this->userLevel <= 1;`
- **What:** `users.userLevel` is `char(1) DEFAULT NULL`. `(int) null === 0`, and
  `0 <= 1`, so a row with a NULL level satisfies `isAdmin()` and is treated as a
  top-level admin everywhere the model method is used.
- **Trigger:** a user row whose `userLevel` is NULL (the column default; legacy
  or imported rows). Login as that account.
- **Impact:** privilege escalation to admin — the account unlocks admin-only
  surfaces and the level-0-only areas gated on `isAdmin()`.
- **Fix:** treat only the documented values as admin, e.g.
  `return in_array((string) $this->userLevel, ['0', '1'], true);`.

### A3 — Level-1 admin can promote to top admin, or demote the last one — High

- **Where:** `app/Http/Controllers/Admin/MakeAdminController.php:41` (gate
  `isAdmin()`, i.e. `<= 1`), `:46` (`'userLevel' => ['required', 'in:0,1,2']`),
  `:56` (write).
- **What:** the user-level operation is gated to any admin, has no self-action
  guard and no last-top-admin guard. The UI only exposes the control to
  `$viewerLevel === 0` (`resources/views/admin/participants.blade.php:388`) and
  even prints "You cannot change your own user level" (`:391-393`) — the
  controller enforces neither.
- **Trigger:** as a `userLevel=1` admin, `PUT /admin/users/{id}/level` with
  `userLevel=0` (self or another account). Conversely a top admin can set the
  sole top admin to `2` and lock the organisation out.
- **Impact:** privilege escalation to top admin; or permanent loss of
  administrative access (top-level surfaces, purges, preferences).
- **Fix:** gate the action to `userLevel === 0`; reject self-demotion and
  demotion of the last remaining top-level admin.

### A4 — Level-1 admin can reset a top admin's password — High

- **Where:** `app/Http/Controllers/Admin/ChangeUserPasswordController.php:50-59`.
- **What:** gated by `isAdmin()` (`<= 1`); `update()` never checks the target
  exists or is lower-privileged (only `edit()` checks existence). The UI exposes
  the change-password control only inside the level-0 block
  (`participants.blade.php:406`), but the endpoint does not.
- **Trigger:** as a `userLevel=1` admin,
  `PUT /admin/users/{topAdminId}/password` with any valid pair.
- **Impact:** account takeover of a more privileged account.
- **Fix:** top-admin gate plus a target-role check; reuse the same guard on the
  GET and PUT.

### A5 — Top-admin guards fail open on a null user — Medium

- **Where:** `app/Http/Controllers/Admin/PublishResultsController.php:29` and
  `app/Http/Controllers/Admin/EntriesController.php:212` —
  `(int) $request->user()?->userLevel !== 0`.
- **What:** `(int) null === 0`, so a null user passes the level-0 check. Today
  the `auth` middleware blocks anonymous callers, but the guard does not assert
  a user — it is one middleware change away from being open.
- **Trigger:** request reaching the action without an authenticated user (e.g. a
  future route/group change), or a NULL-level account (see A2).
- **Impact:** latent — unauthenticated winner publication / entry purge.
- **Fix:** require the user explicitly, e.g.
  `if ($request->user() === null || (int) $request->user()->userLevel !== 0)`.

---

## B. Data loss and corruption

### B1 — Saving a participant 500s whenever an optional field is blank — High

- **Where:** `app/Http/Controllers/Admin/ParticipantsController.php:248-251`,
  with `private static function blankToNull(string $v)` at `:312`.
- **What:** the validated array is mapped through `blankToNull`, whose parameter
  is non-nullable `string`. Laravel's `ConvertEmptyStringsToNull` turns every
  blank optional input into `null`, so the callback receives `null` and throws.
  Verified locally: `array_map('f', [null])` with `function f(string $v)`
  throws `TypeError` in both strict and weak mode (the callback is invoked by
  internal code, so the parameter type is enforced regardless).
- **Trigger:** the edit form always posts `brewerPhone1`, `brewerAddress`,
  `brewerCity`, `brewerState`, `brewerZip`, `brewerClubs`
  (`resources/views/admin/participants_edit.blade.php:46-86`); leave any one
  blank and save.
- **Impact:** participant profile edits fail with HTTP 500 for essentially every
  real participant. Existing tests omit those keys entirely, so `validated()`
  never contains a null entry and the bug is untested.
- **Fix:** make the helper accept `?string` (`is_string($v) && $v === ''`) or
  coalesce before mapping.

### B2 — Saving Competition Info silently wipes the QR check-in password — High

- **Where:** `app/Http/Controllers/Admin/CompetitionInfoController.php:210-212`
  (unconditional write of `contestCheckInPassword`, including `null`).
- **What:** the field lives only in the separate QR modal form
  (`resources/views/admin/competition-info.blade.php:459`); the main `form1`
  never posts it. `storageRow()` writes the column on every main save, and an
  absent key evaluates to `null`, clearing the stored hash.
- **Trigger:** set a QR password in the modal, then save the main Competition
  Info form for any reason.
- **Impact:** silent data loss — QR/mobile check-in is disabled with no warning.
  The dedicated `updateQrPassword` path (`:138-156`) is correct; only the main
  save is destructive.
- **Fix:** omit the column from the update array when the key is absent, or read
  the existing value and preserve it.

### B3 — Blank PayPal secret wipes an env-configured secret — High

- **Where:** `app/Support/Payments/PayPalSettings.php:60-72` (`save`) and
  `:117-122` (`existingSecret`); `PaymentSetupController.php:69`.
- **What:** `save()` treats a blank `client_secret` as "keep the existing one",
  but `existingSecret()` reads only the DB column — never the env fallback. The
  setup form allows a blank secret whenever `hasSecret()` is true, and that
  method *does* consider env (`get()` falls back to `fromEnv()`).
- **Trigger:** install PayPal via `.env`, open `/admin/payments/setup`, change
  mode or webhook id, leave Client Secret blank, save.
- **Impact:** the saved row (empty secret) now shadows env; `configured()`
  flips false and PayPal is silently disabled. Money path lost without an error.
- **Fix:** in `existingSecret()`, fall back to `fromEnv()['client_secret']`; or
  only write the key when the submitted value is non-empty.

### B4 — Shipped `bcoe` styles can be deleted or overwritten — High

- **Where:** `app/Http/Controllers/Admin/StylesAdminController.php:169`
  (`destroy` deletes any id), `:139-145` (`update` rewrites any id), with
  `validatedRow()` forcing `brewStyleOwn => 'custom'` and the active version
  (`:291-292`).
- **What:** the blade shows Edit/Delete only for `brewStyleOwn !== 'bcoe'`
  (`resources/views/admin/styles.blade.php:61`), but the controller enforces no
  such guard — unlike its sibling `StyleTypesController` (`:117-121`).
- **Trigger:** `DELETE /admin/styles/{bcoeId}`, or `PUT /admin/styles/{bcoeId}`.
- **Impact:** shipped system styles are deleted, or converted to custom and
  relocated to the current style set. They cannot be recreated through the UI,
  and entry sort codes / outputs keyed on them break.
- **Fix:** refuse when the row's `brewStyleOwn === 'bcoe'`, on destroy and on
  update/edit.

### B5 — Payments ledger always records USD — Medium

- **Where:** `app/Support/Payments/PaymentService.php:97-111` — the `payments`
  insert has no `currency` key; the migration defaults it to `'USD'`
  (`database/migrations/2026_08_24_000000_create_payments_table.php:45`), and
  `resources/views/backoffice/payments.blade.php:34` prints the stored value.
- **What:** a non-USD competition charges in its own currency but logs every
  ledger row as USD.
- **Trigger:** `prefsCurrency` other than USD, then any successful payment.
- **Impact:** the payment ledger — and the refund ledger derived from it —
  reports the wrong currency for every collection.
- **Fix:** persist the currency actually charged (from the provider/config).

### B6 — Sponsor logo cleared on an unrelated save — Medium

- **Where:** `app/Http/Controllers/Admin/SponsorsController.php:146` —
  `'sponsorImage' => self::blankToNull((string) ($data['sponsorImage'] ?? ''))`.
- **What:** when `public/user_images` has no eligible files the blade renders no
  `sponsorImage` control, so the key is absent from the POST and the row is
  written `null`, clearing the stored logo.
- **Trigger:** edit any sponsor (change only the name) while no images exist, or
  when the stored filename is not in the dropdown list (e.g. an `.svg`, see D6).
- **Impact:** silent loss of the sponsor's logo.
- **Fix:** preserve the existing value when the key is absent.

---

## C. Broken UI flows

### C1 — Entries page: nested forms break every admin action and row delete — High

- **Where:** `resources/views/admin/entries.blade.php` — outer form at `:30`,
  closed at `:417`; inner forms at `:136`, `:149`, `:157`, `:174`, `:400`.
- **What:** the bulk update form wraps the whole page, and the Admin Actions
  forms and each row's Delete form are opened inside it. Nested `<form>`
  elements are invalid HTML: the parser discards the inner start tags, so those
  buttons become submit buttons of the outer form, and the orphaned
  `_method=DELETE`/hidden values ride along.
- **Trigger:** open `/backoffice/entries` and click "Mark All as Paid", any
  Purge action, "Regenerate Judging Numbers", or a row's trash icon.
- **Impact:** those actions submit `PUT/DELETE /backoffice/entries` instead of
  their own route — 405 or an unintended bulk re-save. Entry deletion is
  unusable.
- **Fix:** move the inner forms outside the outer form (or associate them with a
  separate form using the `form` attribute).

### C2 — Styles page: nested form breaks bulk update and delete — High

- **Where:** `resources/views/admin/styles.blade.php` — outer bulk form `:19`,
  closed `:77`; per-row delete form `:63-68`; bulk submit `:75`.
- **What:** same invalid nesting. The inner `</form>` (`:68`) closes the outer
  form early, so the "Update Accepted Styles" button (`:75`) ends up outside any
  form and submits nothing; the row Delete button submits the outer action with
  a leftover `_method=DELETE`.
- **Trigger:** any custom style exists (so the inward form renders), then reload
  `/admin/styles` and try to save the checklist or delete the row.
- **Impact:** the accepted-styles list can no longer be saved; custom-style
  delete returns 405.
- **Fix:** move the delete form outside the bulk form.

### C3 — Upload delete link is a GET to a POST-only route — High

- **Where:** `resources/views/admin/upload.blade.php:60` —
  `href="{{ url('/admin/upload/delete') }}?action=delete&filter=…"`; route is
  `Route::post('/admin/upload/delete', …)` (`routes/admin.php:88`), and
  `UploadController::destroy()` reads `$request->input('file')` (`:116`).
- **What:** the link never reaches the controller — wrong HTTP verb — and even
  if it did, it sends the filename as `filter`, not `file`.
- **Trigger:** click the trash icon on any uploaded sponsor logo.
- **Impact:** HTTP 405; the image is never deleted, yet the UI implies success.
- **Fix:** make it a CSRF-protected POST form that posts `file`, or add a GET
  branch that reads `filter`.

### C4 — Participants list 500s when a participant is assigned to a table — High

- **Where:** `resources/views/admin/participants.blade.php:410` —
  `str_contains((string) ($tableAssignments[$p->uid.'|J'] ?? ''), 'Judge')`.
- **What:** `$tableAssignments` values are Collections of arrays
  (`ParticipantsController.php:129-139`, `->groupBy(...)->map(...)`), and
  `Illuminate\Support\Collection` has no `__toString`. Casting it throws
  `Error: Object of class Illuminate\Support\Collection could not be converted
  to string`.
- **Trigger:** load `/backoffice/participants` when at least one participant has
  a `J` row in `judging_assignments`.
- **Impact:** the Participants page returns HTTP 500.
- **Fix:** test the assignment without string-casting, e.g.
  `$tableAssignments[$p->uid.'|J'] ?? collect()` and check `->isNotEmpty()`
  (the `$staffJudge` half already works).

### C5 — Participant row icons point at the wrong target — Medium

- **Where:** `resources/views/admin/participants.blade.php:406` ("Change X's
  password" → `/user/password`) and `:390` ("Change X's User Level" →
  `/backoffice/participants?bid=…`).
- **What:** `/user/password` is `ChangePasswordController`, which updates the
  authenticated user's own password (`app/Http/Controllers/Auth/ChangePasswordController.php:53`);
  `bid` is not read by `ParticipantsController::index`, so the level link just
  reloads the list.
- **Trigger:** a top admin clicks the key icon (or the lock icon) on another
  participant's row.
- **Impact:** the admin unknowingly resets their own password instead of the
  participant's; the user-level action is a dead link.
- **Fix:** link to `admin.change_user_password.edit` and `admin.make_admin.edit`.

---

## D. Correctness

### D1 — Inverted ternary renders a 1970 judging-close date — Medium

- **Where:** `app/Http/Controllers/Admin/AllDatesController.php:256` —
  `$jClosed = $judgingEarliest === '' ? (int) $judgingEarliest + 86400 : $now + 86400;`
- **What:** the branches are swapped. With no judging sessions
  (`$judgingEarliest === ''`) it builds `(int) '' + 86400 = 86400` → 1970-01-01;
  with a session present it ignores the session date and uses `$now`.
- **Trigger:** `prefsEval=1`, judging close set, judging open blank, and no
  judging-location rows.
- **Impact:** the prefilled judging-close field shows a nonsense 1970 date; an
  admin who saves stores epoch 86400 (judging closes immediately).
- **Fix:** `$judgingEarliest === '' ? $now + 86400 : $judgingEarliest + 86400`.

### D2 — Entry Status "Total Fees" ignores the fee model — Medium

- **Where:** `app/Http/Controllers/Admin/EntriesController.php:113`, `:116`,
  `:119` — `…->count() * $fee` with `$fee = (float) contestEntryFee`.
- **What:** flat `count × base fee`, ignoring volume discounts, the member
  rate and the fee cap — all of which `App\Support\Payments\FeeCalculator`
  (the project's single money model) applies everywhere else.
- **Trigger:** open the Entry Status modal with a discount/member rate/cap set.
- **Impact:** the modal over- or under-states fee totals.
- **Fix:** total through `FeeCalculator` per entrant, as the dashboard already
  does.

### D3 — Custom-style writer drops `brewStyleType` — Medium

- **Where:** `app/Http/Controllers/Admin/StylesAdminController.php:206-212`
  (`appendToSelectedStyles`) vs the bulk writer at `:88-95`, which includes
  `brewStyleType`.
- **What:** a custom style added/edited through the form is written into
  `prefsSelectedStyles` without the type key that the bulk path and consumers
  expect.
- **Trigger:** create or edit an active custom style.
- **Impact:** consumers reading `brewStyleType` from selected-style rows get a
  missing key for those styles (e.g. the home-page hero-image pool).
- **Fix:** include `'brewStyleType' => (int) $data['brewStyleType']` in the map.

### D4 — Style-rename cascade trusts a client field — Medium

- **Where:** `app/Http/Controllers/Admin/StylesAdminController.php:149-154`.
- **What:** the cascade that updates `brewing.brewStyle` matches on the posted
  hidden `brewStyleOld`, not on the row that was just loaded (`$current`). A
  crafted value mass-renames entries of an arbitrary style.
- **Trigger:** `PUT /admin/styles/{id}` with a `brewStyleOld` that differs from
  the row's actual name.
- **Impact:** integrity — unrelated entries are silently renamed.
- **Fix:** use `(string) $current->brewStyle` — **rejected.** The pinned test
  `AdminScreensStylesTest::test_edit_renames_style_and_cascades_into_brewing`
  posts a `brewStyleOld` that deliberately differs from the row's own name and
  expects the cascade to fire, so keying on the posted value is the intended
  legacy contract. Hardening it would break parity; the UI always posts the
  row's own name. Left unchanged, test untouched.

### D5 — Sponsors bulk update skips validation — Medium

- **Where:** `app/Http/Controllers/Admin/SponsorsController.php:105-106`.
- **What:** the inline bulk path writes `sponsorLevel` and `sponsorImage`
  without the `in:1,2,3,4,5` / `max:255` rules the add/edit path applies
  (`:136-138`).
- **Trigger:** `PUT /admin/sponsors` with an out-of-range level or an arbitrary
  image string.
- **Impact:** values that the main form rejects are persisted from the list
  form; the two write paths diverge.
- **Fix:** validate the bulk inputs with the same rules.

### D6 — Sponsor logo list omits SVG the uploader accepts — Medium

- **Where:** `app/Http/Controllers/Admin/SponsorsController.php:163` lists
  `jpg, jpeg, png, gif, webp`; `UploadController.php:27` accepts `.svg` and
  stores it.
- **What:** an uploaded `logo.svg` never appears in the sponsor logo dropdown,
  so it cannot be selected.
- **Trigger:** upload an SVG via `/admin/upload`, then open a sponsor form.
- **Impact:** a supported upload type is a dead end for sponsors (and can cause
  the clearing behaviour in B6).
- **Fix:** add `svg` to the list (and confirm the public serving path renders
  uploaded SVGs safely).

### D7 — Dangling `for=` on two Competition Info labels — Low

- **Where:** `resources/views/admin/competition-info.blade.php:318` and `:334`.
- **What:** labels point at `competition_rules` / `competition_packing_shipping`,
  but `x-markdown-textarea` renders ids from the `id` prop —
  `contestRules` / `competitionPackingShipping` (`:320`, `:336`).
- **Trigger:** click the label text.
- **Impact:** the label does not focus its field; screen readers mis-associate.
- **Fix:** point `for` at the rendered ids.

### D8 — "Unpaid" filters exclude NULL `brewPaid` — Low

- **Where:** e.g. `app/Http/Controllers/Admin/EntriesController.php:130` and the
  `totalFeesUnpaid` count at `:119` use `where('brewPaid', '!=', 1)`, which SQL
  evaluates as false for NULL.
- **What:** the purge path treats NULL as unpaid
  (`where('brewPaid','0')->orWhereNull('brewPaid')`), so the two disagree.
- **Trigger:** entries whose `brewPaid` is NULL (the legacy column default).
- **Impact:** NULL rows never appear in `view=unpaid` or the unpaid totals but
  are still purged as unpaid.
- **Fix:** add `->orWhereNull('brewPaid')` to the unpaid predicates.

### D9 — Delete/update report success for missing ids — Low

- **Where:** `ContactsController.php:83`, `ModsController.php:102`,
  `SponsorsController.php:121`, `StyleTypesController.php:120` (destroy),
  `StylesAdminController.php:169`.
- **What:** no existence check before `update`/`delete`, yet all redirect with a
  success message.
- **Trigger:** `PUT`/`DELETE` a non-existent id.
- **Impact:** a no-op is reported as success.
- **Fix:** check the row exists and redirect honestly (404 or an error message).

### D10 — Dead emptiness guard on a Collection — Low

- **Where:** `app/Http/Controllers/Admin/ParticipantsController.php:293` —
  `if ($entryIds !== [])`, where `$entryIds` is a Collection (`pluck`).
- **What:** a Collection never `=== []`, so the guard never fires. Harmless
  today (an empty `whereIn` compiles to a false predicate), but it is not the
  guard it appears to be.
- **Fix:** `if ($entryIds->isNotEmpty())`.

---

## Leads not yet confirmed (reported, unverified)

These came out of the same sweep but were not read end-to-end; confirm before
acting:

- `EntriesByStyleController` / `EntriesBySubstyleController` issue per-style
  count queries (N+1 over ~150 styles) — behavioural output is correct.
- `EntriesController::update` and the inline judging-number save may 500 or
  store malformed data on crafted input (`explode('-', $brewStyle)` destructuring
  and an unvalidated `brewJudgingNumber`).
- `PublishResultsController` / `RegenerateNumbersController` share the A5
  null-user guard idiom.

## Deliberately excluded

The three baseline failures documented in `docs/HANDOFF-OPEN-ISSUES.md`
(`All By Table` link, `/admin/output/pullsheets` 403, `JudgingConfigTest`
timezone) are pre-existing on a clean checkout and are not re-reported here.

## Suggested fix order

1. A1, A2, A3, A4 (authorization — cheapest and highest blast radius).
2. B1, B2, B3 (active data loss / broken saves).
3. C1–C4 (broken flows; C1/C2 are one-markup-element fixes each).
4. B4, C5, D1–D6, then the Low items.
