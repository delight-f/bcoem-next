# P4.5 — Barcode check-in flow

> Part of spec §6 P4.5. Legacy surface: `admin/barcode_check-in.php`.

Status: done
Phase: 4 (Slice C)
Depends on: P4.1 (locations), entry lifecycle (received flags — Slice B)
Consumes: ledger/entry-lifecycle.md (received-flag semantics)

## Goal

Port the barcode-driven entry check-in: scan → look up entry by judging
number/barcode → flip received state, with the same flag semantics and
duplicate/unknown-scan handling as legacy.

## Scope

- Scan input handling (keyboard-wedge barcode scanners = fast text input;
  no native camera APIs needed).
- Received-flag writes match entry-lifecycle ledger; undo path if legacy
  has one.

## Deliverables

1. Check-in screen + endpoint.
2. Feature tests: known scan, unknown scan, duplicate scan, undo.

## Acceptance

- DB flag transitions on a corpus dump identical to legacy for scripted
  scan sequences.
- Test suite green; PHPStan 0; Pint clean.
