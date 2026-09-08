# P5.6 — Archive + purge admin flows

> Part of spec §7 P5.6. Legacy surface: archive.admin.php +
> includes/process/process_archive.inc.php, includes/data_cleanup.inc.php.
> Ledger: ledger/archive-purge.md — **HIGHEST DATA-LOSS-RISK module.**

Status: done
Phase: 5 (Slice D)
Depends on: P5.4/P5.5 admin shell; signed retention statement
Consumes: ledger/archive-purge.md (pins 1–8 + disposition matrix — REQUIRED)

## Goal

Competition close-out: rename-and-recreate archive plus the purge/reset
flows, behavior-identical to ledger pins.

## Scope

- Archive: RENAME TABLE t→t_<suffix> + CREATE TABLE t LIKE t_<suffix> per
  pin 1; keep-* checkbox membership lists per pins 2–5; contestID nulling,
  user_docs recursive delete warning surfaced in UI (pin 6).
- Purge flows per pin 7: unpaid/stale entry cleanup with children, scores
  reset, tables/flights reset, special-best reset, staff/assignments/
  evaluation/payments truncates; SINGLE mode DELETE-by-comp_id variant.
- Confirmation UX must state irreversibility explicitly (retention
  statement items 1–4).
- ⚠ The retention statement in ledger/archive-purge.md requires human
  sign-off BEFORE implementation starts — verify the `[X] Signed off` box,
  else block and ask.

## Deliverables

1. ArchiveController + purge actions; DB-level integration tests extending
   ArchiveMechanicsDbTest coverage to the HTTP path (rename+recreate on a
   real schema copy, keep-flag matrix).
2. Ledger updated with any divergences.

## Acceptance

- Integration test proving: history preserved in sibling tables, live
  tables empty + structurally identical, AUTO_INCREMENT reset, current
  admin survives participant purge.
- Destructive actions unreachable without confirmation; no silent deletes
  beyond documented legacy behavior.
