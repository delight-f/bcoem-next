# P2.6 — Slice A parity gate


> **Scope note (2026-08-24, owner-approved):** Slice A was remapped from
> spec §4's upstream-style standalone URLs to the live fork's actual public
> surface: most info sections render inside the landing page at `/`;
> `past-winners` (hyphen) is the archive route; `/list` is account-gated;
> winners/BOS/bestbrewer render as results sections post-reveal. Parity
> oracle: modernization branch.
Status: done (PASS 2026-08-24 — zero diffs on anon-base, synth-100-winners-shown, and CI baseline; summary in parity/slice-a/summary.md)
Phase: 2 (Slice A)
Depends on: P2.2, P2.3, P2.4, P2.5

## Goal

Run the parity harness across every Slice A URL × corpus dumps; reduce diffs
to the cosmetic threshold; declare Slice A done.

## Scope

- Extend `tools/parity/parity.sh` URL list to the full Slice A route set.
- CI job (or local script) loading anon-base + synth dumps: extend ci.yml or
  add a workflow that imports derived dumps into throwaway DBs before the
  parity run — closes the flight-ledger gap about dump-loading.
- Normalize rules review: confirm normalize.php treats dates/session ids/
  csrf tokens as volatile; tighten if diffs are cosmetic-but-noisy.
- Triage every remaining diff into: fix-in-port / normalize-rule / accepted
  legacy-weirdness (ledger row each).

## Deliverables

1. Parity report artifacts for all URLs × dumps committed under
   `.scratch/bcoem-next/parity/slice-a/` (summary, not raw HTML).
2. Updated spec §4 checkboxes.

## Acceptance

- Zero non-cosmetic diffs on Slice A routes for anon-base and one synth
  variant; documented verdict for every residual diff.
