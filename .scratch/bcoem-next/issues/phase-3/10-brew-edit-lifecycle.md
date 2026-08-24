# P3.3b — Entry edit + lifecycle gates

> Part of spec §5 P3.3. Legacy surface: `pub/brew.pub.php` (edit mode),
> `includes/process_brewing.inc.php` (edit branch — gates at :61, admin
> re-read at :269–279, style-reset at :531+).

Status: not started
Phase: 3 (Slice B)
Depends on: P3.3a (brew create)
Consumes: ledger/entry-lifecycle.md (#3, #4, #11, #12)

## Goal

The entrant edits an existing entry with legacy's exact gates, and admins
can set paid/received at edit time; editors of mead/cider variants keep the
required style fields or the row is unconfirmed again.

## Scope

- Edit gating (ledger #4): style/category fields only editable while
  `entry_window_open == 1`; other fields per legacy conditions (pin which
  fields are always editable — e.g. name/info).
- Admin-only flags (ledger #3): `userLevel<=1` may set `brewPaid`/
  `brewReceived` from the edit form; entrants' submitted values are
  re-read from DB (never trusted from the form).
- Style-field reset (ledger #11): missing required style fields after edit
  set `brewConfirmed='0'` and redirect with `msg=1-<style>` — reproduce
  exactly (message is parity surface).
- Judging number preserved on edit (never reallocated); `brewUpdated`
  timestamp semantics from `process_brewing.inc.php`.
- Edit form reuse: same view as create with mode switch; the style pickers
  must not offer styles the window no longer allows (window check happens
  server-side on POST regardless).

## Deliverables

1. BrewController edit action + mode-aware view.
2. Feature tests: window-open vs closed style edits; admin paid/received
   write; entrant flag re-read (POST tamper ignored); mead/cider required-
   field reset + msg code; timestamp update.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Edit round-trip on a corpus dump preserves all non-edited columns (diff
  row before/after).
