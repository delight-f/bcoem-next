# P5.1 — PDF pipeline decision + pullsheets first output

> Part of spec §7 P5.1. Legacy surface: `output/pullsheets.output.php`,
> `includes/fpdf/*` (vendored FPDF 1.x), `includes/output.inc.php` dispatch.

Status: pending
Phase: 5 (Slice D)
Depends on: Slice C (judging data complete — pull sheets read tables/flights/entries)
Consumes: ledger/flight-assignment.md (row ordering), ledger/styles.md

## Goal

Decide and lock the PDF generation strategy for all of Slice D, validated by
porting exactly one output end-to-end (pullsheets) before any other output
starts.

## Scope

- **Decision to make**: replace vendored fpdf. Options:
  - dompdf (HTML+CSS → PDF; Blade views reusable, closest to Laravel idiom)
  - snappy/wkhtmltopdf (binary dep, deprecated upstream)
  - keep raw coordinate-drawing with a maintained FPDF fork

  Recommendation: **dompdf** — outputs become Blade templates instead of
  porting ~20 files of `Cell()`/`Ln()` coordinate code; deterministic enough
  for visual parity review.
- Port `pullsheets.output.php` under the chosen pipeline as the validation
  case: per-table pull sheets listing entries in judging-number order.
- Establish the shared pattern the rest of P5.2 copies: route naming
  (`/admin/output/{type}`), stream vs download, filename convention,
  auth gate.

## Deliverables

1. Decision recorded in `.scratch/bcoem-next/ledger/outputs.md` (new) with
   rationale + dependency added to composer.json.
2. Pullsheets PDF working on a corpus dump.
3. Feature test asserting HTTP 200 + PDF magic bytes + entry count matches
   received entries for a table.

## Acceptance

- Visual side-by-side legacy vs port pull sheet approved by repo owner.
- Pattern documented so P5.2 agents don't re-litigate the pipeline.
