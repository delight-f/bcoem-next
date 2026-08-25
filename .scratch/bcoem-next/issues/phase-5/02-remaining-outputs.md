# P5.2 — Remaining outputs in pairs

> Part of spec §7 P5.2. Legacy surface: every `*.output.php` except
> pullsheets (P5.1) and export (P5.3): labels, bottle_label, table_cards,
> sorting, shipping_label, entry, participant_summary,
> participant_entries_list, post_judge_inventory, judge_notes, assignments,
> staff_points, styles, maps, dropoff, print, results, bos_mat,
> scoresheets.

Status: pending
Phase: 5 (Slice D)
Depends on: P5.1 (pipeline locked), P4.4 (scores/BOS data)
Consumes: ledger/scoring.md, App\Support\Results\ResultsRepository (best-brewer points already live — display only)

## Goal

Port all remaining PDF/HTML outputs using the P5.1 pattern, two per pass to
keep diffs reviewable.

## Scope

- Pair order (data dependencies first):
  1. labels + bottle_label (entry data only)
  2. table_cards + sorting (tables/flights)
  3. assignments + judge_notes (judging_assignments)
  4. results + bos_mat (BOS/winners — reuse ResultsRepository)
  5. participant_summary + participant_entries_list (per-brewer views)
  6. post_judge_inventory + staff_points (inventory + best-brewer display)
  7. styles + maps + dropoff + print (config-driven sheets)
  8. scoresheets (existing uploaded-file bundling — check whether this is
     file assembly rather than generation before porting)
- Any output whose legacy logic is trivially wrong stays wrong only if
  documented in ledger/outputs.md (Slice C divergence precedent).

## Deliverables

1. One route + controller action (or shared OutputController) per output.
2. Feature tests: HTTP 200 + content-type + row-count sanity per output.
3. ledger/outputs.md rows for each: inputs read, quirks mirrored.

## Acceptance

- Full URL inventory diff shows both apps serving every output URL.
- Repo owner visually approves results/bos_mat/table_cards at minimum.
