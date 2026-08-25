# P5.3 — Export CSV/XLSX column-for-column

> Part of spec §7 P5.3. Legacy surface: `output/export.output.php`.

Status: pending
Phase: 5 (Slice D)
Depends on: P5.2 (entry/score data shapes settled)
Consumes: —

## Goal

Byte-comparable CSV export (the one artifact class the graduation test
byte-compares — spec §8.3).

## Scope

- Port export.output.php's column set, header row, quoting, and ordering
  exactly; XLSX flavor via whatever the legacy actually emits (verify:
  it may be an HTML-table-with-xls-mime trick — mirror or document).
- Byte comparison harness leg: extend tools/parity to fetch both exports on
  identical dumps and cmp.

## Deliverables

1. `/admin/output/export` equivalent producing identical bytes on anon-base
   dump.
2. Parity harness extension + green run for the export URL.

## Acceptance

- `cmp legacy.csv port.csv` → identical on at least one corpus dump;
  any difference explained byte-by-byte in ledger.
