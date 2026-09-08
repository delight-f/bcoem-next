# Perf smoke — spec §8.5 (2026-08-25T15:21:15Z)

- Dump: `synth-500.sql` (500 brewing rows)
- Samples: 25/page (+3 warm-up), port on :8094, php 8.4.23
- Gate: p95 < 300 ms on every port page

| Page | port p50 (ms) | port p95 (ms) | legacy p50 (ms) | legacy p95 (ms) |
|---|---|---|---|---|
| home / | 47 | 53| — | —  |
| entries list /list (entrant) | 47 | 54| — | —  |
| past winners /past-winners/p1 | 201 | 225| — | —  |
| flights grid (admin) | 43 | 50| — | —  |
| scores page (admin) | 39 | 45| — | —  |
| results section (admin) | 248 | 262| — | —  |
| login POST /login | 814 | 820 **MISS**| — | —  |

## Verdict

**MISS** — pages over 300 ms p95: `login_post` (820ms). See profile notes below.

PROFILE /login (POST)
status: 302 | wall: 820ms (p95)
hotspot: single bcrypt password_verify at hash cost 12 takes 773ms on this host — CPU-bound key-derivation by design, not query or framework overhead. Render-path pages are unaffected.
