# Slice A parity gate — results

Status: **PASS** (2026-08-24)
Harness: `tools/parity/parity.sh` (v1, content-level comparison — option B)
Oracle: modernization branch of `brewcompetitiononlineentry` (local checkout)

## Verdict matrix

| URL (legacy → port) | anon-base | synth-100-winners-shown | baseline (CI) |
|---|---|---|---|
| `/` (landing) | PASS | PASS | PASS |
| `index.php?section=past-winners&go=demoarchive` → `/past-winners/demoarchive` | PASS | PASS | PASS |
| `index.php?section=list` → `/list` | PASS | PASS | PASS |

Every URL × dump: **0 content diffs** after normalization
(`tools/parity/normalize.php` → `content.php` → `chrome-exclude.txt`).
Raw per-URL artifacts live in `tools/parity/reports/run-*` (gitignored).

## Harness fixes landed with this gate

1. **Per-run legacy reconfiguration** — `site/config.php` is regenerated for
   each run's throwaway DB (`configure-legacy.py` invoked from `parity.sh`);
   previously the oracle read a stale DB, so both apps compared different
   data.
2. **Table-prefix override** — `PARITY_DB_PREFIX` now honors an empty value
   (unprefixed tenant dumps like `anon-base.sql`); previously `${VAR:-baseline_}`
   forced `baseline_` and the port 500'd on real tenant shapes.
3. **Double-slash URL bug** — leading `/` stripped from paths before joining,
   so `/past-winners/…` and `/list` no longer 404 on the port.
4. **Cleanup** — single EXIT trap now drops the throwaway DB and kills both
   servers (previously the second `trap EXIT` replaced the first, leaking
   DBs and a stray `php -S`).
5. **Chrome exclusions** — `chrome-exclude.txt` extended/reordered so the
   login modal's `Password`/`Reset` residue is stripped (auth modal is
   out-of-scope chrome for the anonymous slice).

## Port fixes landed with this gate

Landing (`/`) now reproduces the legacy composition (`index.pub.php` +
`default.pub.php` + `judge_closed.pub.php`):

- **Nav gating** — Rules/Volunteers links only before judging starts;
  Entry Info only while future judging sessions remain; Sponsors gated on
  `prefsSponsors` + row count; Contact always.
- **Judge-closed blurb** — "Thanks to all who participated… There were N
  entries judged and M registered participants…" (received-entry and
  participant counts) shown once registration/entry are closed and no
  future session remains, in every winner-display state.
- **Results vs cards gating** — winners replace the at-a-glance cards only
  when `prefsDisplayWinners=Y` AND the reveal delay has strictly passed;
  cards render otherwise (window states), never alongside results.
- **Salutation placement** — rendered in the header after the hero, before
  the print-only heading (removes the duplicated competition name).
- **past-winners gate** — unknown/undisplayable archive suffixes redirect
  to `/?msg=8` ("Archived data is not available."), matching
  `constants_post_lang.inc.php`; `/list` anonymous redirects to `/?msg=99`.
- **msg alerts** — rendered between nav and hero like legacy
  (`alerts.pub.php` placement).

## Residual diffs

None on the gate's URL × dump matrix.

Not exercised by this gate (documented, not ported yet):

- Winner-delay announcement state (`prefsDisplayWinners=Y` with the delay
  still in the future) — no corpus dump sits in that window.
- Displayable-archive past-winners rendering — no corpus dump has an
  archive with `archiveDisplayWinners=Y` + sibling tables + scores.
- Sponsors section/nav link with rows present — `anon-base` has
  `prefsSponsors=N`, baseline has no sponsors.
- "Other Info" nav link (`custom_competition_info.pub.php` existence) —
  never present in the dumps.

## How to re-run

```sh
# anon-base (unprefixed tenant dump)
LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql \
PARITY_DB_PREFIX= PARITY_DB_PASS=root tools/parity/parity.sh

# synth variant (winners revealed)
# same with DUMP_SQL=~/dev/bcoe/corpus/derived/synth-100-winners-shown.sql

# CI baseline (prefixed)
LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
DUMP_SQL=sql/bcoem_baseline_3.0.X.sql PARITY_DB_PASS=root tools/parity/parity.sh
```
