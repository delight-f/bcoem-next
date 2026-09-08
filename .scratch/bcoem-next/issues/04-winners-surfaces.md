# P2.4 — Winners surfaces (winners, category, subcategory, past_winners, bos, bestbrewer)


> **Scope note (2026-08-24, owner-approved):** Slice A was remapped from
> spec §4's upstream-style standalone URLs to the live fork's actual public
> surface: most info sections render inside the landing page at `/`;
> `past-winners` (hyphen) is the archive route; `/list` is account-gated;
> winners/BOS/bestbrewer render as results sections post-reveal. Parity
> oracle: modernization branch.
Status: done (BOS + winners by prefsWinnerMethod + bestbrewer + past-winners; unknown-archive edge now redirects to ?msg=8 and is feature-tested)
Phase: 2 (Slice A)
Blocks: P2.6
Depends on: P2.1
Consumes: ledger/winners-display.md, ledger/scoring.md

## Goal

The six results pages — highest-visibility parity surface.

## Scope

- Reveal gating: judging_winner_display strict-boundary port (pre-reveal
  admins-only view).
- Selection filters: scorePlace IN 1–5 (FLOAT column in judging_scores;
  varchar(3) 'HM' possible only in bos table) per winners-display ledger.
- Category/subcategory rollups incl. BA-set brewCategory variant.
- `past_winners` from archive tables (`<t>_<suffix>` reads; archive-prefix
  session cache isolation open question from ticket-01 ledger lands here).
- BOS + best-brewer displays with prefsWinnerMethod grouping and custom
  titles (prefsBestBrewerTitle/prefsBestClubTitle).
- Row ORDER within each surface: pin exact order during this ticket (open
  question in winners-display ledger closes here).

## Deliverables

1. Controllers + views for all six routes.
2. Feature tests: fixture scores → exact selection AND row order per surface,
   pre/post reveal × user classes.
3. winners-display ledger updated to close the ordering open question.

## Acceptance

- Parity run on anon-base dump: winners URLs cosmetic-diff only.
