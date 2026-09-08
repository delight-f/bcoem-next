# P3.8 — Slice B gate: end-to-end registration + parity verdicts

> Part of spec §5 P3.8. Acceptance: a volunteer can register, enter, edit
> entries on a staging copy of a real dump with zero console errors; parity
> diffs explained or fixed.

Status: done
Phase: 3 (Slice B) — gate
Depends on: P3.1a–d, P3.2a–d, P3.3a/b, P3.4, P3.5a/c (manual path for the
deterministic E2E; Stripe mocked), P3.6, P3.7
Blocks: Phase 4 (Slice C)

## Goal

Prove the slice end-to-end on a corpus dump and close out spec §5 with a
parity verdict per newly-covered route.

## Scope

1. **E2E scripted season leg** (Parity Gate §8.3 prequel): on a staging copy
   of `anon-base.sql` (and one synth variant): register entrant → brewer
   wizard → create entry → edit entry → pay via MANUAL marking (deterministic
   admin step; Stripe leg validated by mocked adapter in tests) → assert
   final DB state (`users`, `brewer`, `brewing` flags, `payments` rows)
   matches the expected convergence. Zero console errors (browser-driven if
   a JS surface is used — `tools/browser` per harness conventions).
2. **Parity runs**: extend `tools/parity/urls.txt` with the Slice B URL set
   that is anonymous-diffable (login page, register page chrome, closed-state
   variants); run `tools/parity/parity.sh` on anon-base + synth-100-winners-
   shown + CI baseline; triage every diff: fix-in-port / normalize-rule /
   accepted (ledger row each). Auth-gated pages are NOT page-diffed —
   covered by DB-state assertions instead (spec §8.3 payment exception).
3. **Ledger/verdict doc**: `.scratch/bcoem-next/parity/slice-b/summary.md`
   mirroring the slice-a summary format; update ledger/registration-rules.md
   and ledger/payments.md with any newly pinned write shapes (e.g. manual-
   marking write shape — payments ledger open question).
4. **Spec update**: check off P3.1–P3.8 in spec §5.

## Deliverables

1. E2E test (feature test class or scripted artisan command) + parity
   summary + spec §5 checkboxes + ledger addenda.

## Acceptance

- E2E green on anon-base + one synth variant with zero console errors.
- Slice B parity URLs: zero non-cosmetic diffs; every residual diff has a
   ledger verdict.
- `php artisan test` green (entire suite), PHPStan 0, Pint clean.
