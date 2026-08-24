# P3.2d — My entries list (brewer_entries) + account entry management

> Part of spec §5 P3.2/P3.3; completes the P2.3 deferral note ("entries
> browser table lands with P3 auth"). Legacy surface: `pub/brewer_entries.pub.php`
> (account page listing the user's entries), `includes/process.inc.php`
> (delete branch).

Status: done (7911238)
Phase: 3 (Slice B)
Depends on: P3.1d (auth nav), P3.3a (brew create — the list shows created
entries)
Consumes: ledger/entry-lifecycle.md (row states), ledger/payments.md (paid flag)

## Goal

The logged-in user sees their entries (the account-gated `/list` surface):
each brewing row with name, style, judging number, and status badges
(received / paid / confirmed), plus Edit (when the window allows — ledger
#4) and Delete (legacy rules from `process.inc.php`: only while editable,
unpaid/unreceived, window open).

## Scope

- Query: `brewing WHERE brewBrewerID = users.id` (legacy joins brewer for
  names — pin whether the list uses the brewer row or stored
  `brewBrewerFirstName/LastName`); order per `brewer_entries.pub.php`
  (creation or judging number — pin exact order).
- Status badges: derive from `brewReceived`/`brewPaid`/`brewConfirmed`
  flags; label strings from legacy lang (parity surface).
- Edit link target: `?section=brew&go=edit&filter=<id>` → P3.3b route.
  Delete: POST with the legacy confirmation + redirect back to the list with
  the legacy msg code (pin which).
- This page also carries the at-a-glance cards for `section==list`
  (`at-a-glance.pub.php` list branch: judging + entry-registration + drop-off
  + shipping cards gated by `at_a_glance_entry_info` — ledger/contest-info
  L1–L3 apply).

## Deliverables

1. EntriesController (list) + view + delete handling.
2. Feature tests: list contents + order; badge states for
   paid/received/confirmed combos; edit/delete gating by window + flags.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- On a corpus dump with entries, the logged-in user's list matches legacy
  `?section=list` row-for-row (content parity, auth session required).
