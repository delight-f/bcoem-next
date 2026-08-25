# Perf smoke — spec §8.5 (2026-08-25T08:11:37Z)

- Dump: `synth-500.sql` (500 brewing rows)
- Samples: 25/page (+3 warm-up), port on :8114, php 8.4.23
- Gate: p95 < 300 ms on every port page

| Page | port p50 (ms) | port p95 (ms) | legacy p50 (ms) | legacy p95 (ms) |
|---|---|---|---|---|
| home / | 50 | 54| 49 | 52  |
| entries list /list (entrant) | 53 | 60| 46 | 52  |
| past winners /past-winners/p1 | 243 | 272| 48 | 52 (HTTP 302)  |
| flights grid (admin) | 49 | 53| — | —  |
| scores page (admin) | 42 | 46| — | —  |
| results section (admin) | 80 | 85| 44 | 49  |
| login POST /login | 822 | 836 **MISS**| — | —  |
| login POST /login (legacy ref) | — | — | 20 | 22 (HTTP 302) |

## Verdict

**MISS** — pages over 300 ms p95: `login_post` (836ms). See profile notes below.

PROFILE /login (POST)
status: 302 | wall: 836ms (p95)
hotspot: single bcrypt password_verify at hash cost 12 takes 800ms on this host — CPU-bound key-derivation by design, not query or framework overhead. Render-path pages are unaffected.
