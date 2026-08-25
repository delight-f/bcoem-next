# P4.2 — Flight/table assignment engine

> Part of spec §6 P4.2. Depends on ledger/flight-assignment.md (P1.5) and
> its characterization tests. Legacy logic lives behind
> `admin/judging_assign.php` / `admin/judging_flights.php`.

Status: done
Phase: 4 (Slice C)
Depends on: P4.1 (config inputs)
Consumes: ledger/flight-assignment.md

## Goal

Port the flight/table assignment algorithm behind the Phase 1
characterization tests: rounding rules, table caps, judge preference
handling.

## Scope

- Pure service layer first (no HTTP): input = entries + tables + judges +
  preferences rows; output = flight/table assignment rows.
- Characterization tests from P1.5 must pass unmodified against the port.
- Then wire into `judging_assign` admin screen (UI polish may land with
  P4.3 if coupled).

## Deliverables

1. Assignment service, schema-exact writes to flights/assignments tables.
2. All P1.5 characterization tests green; new unit tests for edge cases
   named in the ledger.

## Acceptance

- Running assignment on a corpus dump produces DB rows identical to legacy.
- Test suite green; PHPStan 0; Pint clean.
