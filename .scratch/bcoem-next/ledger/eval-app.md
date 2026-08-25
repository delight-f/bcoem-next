# Behavior Ledger: Evaluation sub-app

> From `EvalConsensusTest.php` (import consensus rule) plus source citations.
> The eval app is 24 files of scoresheet/evaluation UI layered onto the SAME
> tenant database.

## Purpose

Judges' private scoresheet/evaluation workflow, and how consensus
evaluations become official scores.

## Inputs

- Tables touched: `evaluation` (own table), reads/writes `judging_scores`,
  `brewing`, `brewer`, `styles` — all via the main `$prefix` tables
- Session/state dependencies: separate eval session model; archive suffix
  support (`archive_suffix`) for archived-comp evaluation

## Pinned behaviors

| # | Behavior | Pinning test | Notes / edge cases |
|---|----------|--------------|--------------------|
| 1 | eval/ SHARES the tenant DB (db.eval.php uses `$prefix."brewing"` etc.) — no second database | source-only eval/db.eval.php:8-51 | answers map.md fog question; route-prefix design can reuse tenant connection |
| 2 | Consensus rule: exactly one evaluation ⇒ NOT imported (singles bucket) | EvalConsensusTest::provideConsensusGroups | import requires ≥2 judges |
| 3 | Official score from consensus = MAX of judges' evalFinalScore (highest, not average) | same | |
| 4 | Place = max numeric place > 0 else empty sentinel | providePlaces | legacy uses `[]` as "no place" |
| 5 | Import never overwrites existing judging_scores SCORES — only scorePlace/scoreType/scoreMiniBOS update on existing rows; new rows only for entries without a score row | source-only ajax/import_scores.ajax.php:150-181 + modal copy | idempotent re-import: repeated runs converge to same state |
| 6 | Mini-BOS imported as max flag across judges | source-only :199+ | |

## Port verdict per surface (ticket acceptance)

| Surface | Verdict |
|---|---|
| full_scoresheet/full_output | port-on-demand (generic BJCP sheet) |
| structured_scoresheet/structured_output | port-on-demand |
| nw_structured_cider(+output) | drop (single-tenant legacy variant) unless an NW-cider tenant appears |
| checklist_scoresheet/checklist_output | drop (thin wrapper) |
| import_scores (+ajax) | port-now (consensus rule above) |
| dashboard/judging_dashboard/judging_admin | fold into P4.6 admin UI, port-on-demand |
| warnings/descriptors/process | port-on-demand with scoresheets |
| install_eval_db | drop (schema ships with baseline migration instead) |

## Deliberate weirdness

- Consensus by MAX (not mean/median): a lone high outlier judge decides the
  official score once any second judge exists. Preserve in P4.6 or get owner
  deviation sign-off.

## Open questions

- Confirmed 2026-08-25: `evaluation` IS in sql/bcoem_baseline_3.0.X.sql (24 tables incl.
  `baseline_evaluation`). No schema gap for P4.6.
