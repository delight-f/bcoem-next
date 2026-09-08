#!/usr/bin/env bash
# perf_smoke.sh — spec §8.5 graduation gate, performance smoke leg.
#
# Loads a corpus dump (default synth-500) into one throwaway schema shared by
# the Laravel port, boots it via the tools/parity dual-boot pattern
# (optionally plus the legacy oracle at :8093 for reference numbers), warms
# up, times key pages with curl, and asserts every PORT page serves under
# THRESHOLD_MS p95.
#
# Usage:
#   ./tools/graduation/perf_smoke.sh              # port only + assertions
#   LEGACY_CONTEXT=1 ./tools/graduation/perf_smoke.sh
#       # also boot legacy at :8093 and print reference numbers (no asserts)
#
# Env vars (fetch_export.sh conventions):
#   DUMP_SQL        corpus dump to load      (default ~/dev/bcoe/corpus/derived/synth-500.sql)
#   LEGACY_DIR      legacy checkout          (default ~/dev/bcoe/brewcompetitiononlineentry)
#   PORT_LEGACY / PORT_NEW  local ports      (default 8093 / 8094)
#   PARITY_DB_HOST / _USER / _PASS          MySQL connection (root / empty)
#   DB_TABLE_PREFIX dump's table prefix      (default "" — synth corpora are unprefixed)
#   EXPORT_ADMIN_USER / _PASS               provisioned admin login (both apps)
#   SAMPLES         timed samples per page    (default 25, must be >= 20)
#   WARMUP          untimed warm hits per page (default 3)
#   THRESHOLD_MS    p95 gate in milliseconds  (default 300)
#
# Requires: mysql client, curl, php >= 8.3, a local MySQL server.
# Report lands in .scratch/bcoem-next/graduation/perf-smoke.md; any page that
# misses the gate is re-run through profile_page.php so the report can name
# the hotspot (slow query / N+1) instead of just failing.

set -euo pipefail

DUMP_SQL="${DUMP_SQL:-$HOME/dev/bcoe/corpus/derived/synth-500.sql}"
DUMP_SQL="$(realpath "$DUMP_SQL")"
LEGACY_DIR="${LEGACY_DIR:-$HOME/dev/bcoe/brewcompetitiononlineentry}"
PORT_LEGACY="${PORT_LEGACY:-8093}"
PORT_NEW="${PORT_NEW:-8094}"
SAMPLES="${SAMPLES:-25}"
WARMUP="${WARMUP:-3}"
THRESHOLD_MS="${THRESHOLD_MS:-300}"
ADMIN_USER="${EXPORT_ADMIN_USER:-parity.harness@brewingcompetitions.com}"
ADMIN_PASS="${EXPORT_ADMIN_PASS:-parity-harness}"
LEGACY_CONTEXT="${LEGACY_CONTEXT:-0}"

[ "$SAMPLES" -ge 20 ] || { echo "SAMPLES must be >= 20" >&2; exit 2; }

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
REPORT_DIR="$ROOT/.scratch/bcoem-next/graduation"
mkdir -p "$REPORT_DIR"
REPORT_MD="$REPORT_DIR/perf-smoke.md"
WORK="$(mktemp -d)"
DB_NAME="parity_perf_$$"
NEW_URL="http://127.0.0.1:$PORT_NEW"
LEGACY_URL="http://127.0.0.1:$PORT_LEGACY"

MYSQL=(mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u "${PARITY_DB_USER:-root}")
[ -n "${PARITY_DB_PASS:-}" ] && MYSQL+=(-p"$PARITY_DB_PASS")

cleanup() {
    kill "${NEW_PID:-0}" "${LEGACY_PID:-0}" 2>/dev/null || true
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME;" >/dev/null 2>&1 || true
    # KEEP=1 (debug): keep work dir + server logs for triage.
    if [ "${KEEP:-0}" = "1" ]; then
        mkdir -p "$REPORT_DIR/perf-smoke-debug"
        { cp "$WORK"/*.log "$REPORT_DIR/perf-smoke-debug/" 2>/dev/null || true
          cp "$WORK"/login.legacy.html "$REPORT_DIR/perf-smoke-debug/" 2>/dev/null || true; } >/dev/null
        echo "debug artifacts in $REPORT_DIR/perf-smoke-debug (work dir kept: $WORK)" >&2
    else
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

# Fail fast if a sibling harness already owns these ports — sharing them
# silently cross-wires both apps' numbers.
for port in "$PORT_NEW" "$PORT_LEGACY"; do
    [ "$LEGACY_CONTEXT" = "1" ] || [ "$port" != "$PORT_LEGACY" ] || continue
    if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
        exec 3>&- 3<&- || true
        echo "port $port is already in use — set PORT_NEW/PORT_LEGACY or stop the other server" >&2
        exit 3
    fi
done

echo "== loading $DUMP_SQL into $DB_NAME =="
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB_NAME" < "$DUMP_SQL"

# The past-winners gate (ResultsRepository::archiveDisplayable) requires an
# archive row flagged for display plus three sibling snapshot tables holding
# scores. Synth corpora ship none, so scaffold suffix "p1" from live tables —
# the same shape legacy's own archiving produces.
echo "== scaffolding displayable archive suffix p1 =="
"${MYSQL[@]}" "$DB_NAME" <<'SQL'
DROP TABLE IF EXISTS brewer_p1, brewing_p1, judging_scores_p1;
# CREATE TABLE ... LIKE preserves the source indexes — same as legacy's own
# archiving. AS SELECT would strip them and skew the join 100x.
CREATE TABLE brewer_p1 LIKE brewer;                 INSERT INTO brewer_p1 SELECT * FROM brewer;
CREATE TABLE brewing_p1 LIKE brewing;               INSERT INTO brewing_p1 SELECT * FROM brewing;
CREATE TABLE judging_scores_p1 LIKE judging_scores; INSERT INTO judging_scores_p1 SELECT * FROM judging_scores;
INSERT INTO judging_scores_p1 (eid, bid, scoreTable, scoreEntry, scorePlace)
    SELECT id, brewBrewerId, 1, 38.0, '1' FROM brewing LIMIT 500;
DELETE FROM archive WHERE archiveSuffix='p1';
INSERT INTO archive (archiveStyleSet, archiveSuffix, archiveDisplayWinners) VALUES ('smoke', 'p1', 'Y');
SQL

echo "== provisioning admin $ADMIN_USER =="
PREFIX="${DB_TABLE_PREFIX-}"
HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$ADMIN_PASS")"
TBL="users"; [ -n "$PREFIX" ] && TBL="${PREFIX}users"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO \`$TBL\` (user_name,password,userLevel,userCreated,userAdminObfuscate) SELECT '$ADMIN_USER','$HASH','0','2024-01-01 00:00:01',0 WHERE NOT EXISTS (SELECT 1 FROM \`$TBL\` WHERE user_name='$ADMIN_USER');"

echo "== booting servers =="
if [ "$LEGACY_CONTEXT" = "1" ] && [ -d "$LEGACY_DIR" ]; then
    ( cd "$LEGACY_DIR" && \
        PARITY_DB_NAME="$DB_NAME" \
        PARITY_DB_HOST="${PARITY_DB_HOST:-127.0.0.1}" \
        PARITY_DB_USER="${PARITY_DB_USER:-root}" \
        PARITY_DB_PASS="${PARITY_DB_PASS:-root}" \
        PARITY_DB_PREFIX="${DB_TABLE_PREFIX-}" \
        LEGACY_BASE_URL="http://127.0.0.1:${PORT_LEGACY}/" \
        python3 "$ROOT/tools/parity/configure-legacy.py" >/dev/null )
fi
export DB_CONNECTION=mysql
export DB_HOST="${PARITY_DB_HOST:-127.0.0.1}"
export DB_PORT=3306
export DB_DATABASE="$DB_NAME"
export DB_USERNAME="${PARITY_DB_USER:-root}"
export DB_PASSWORD="${PARITY_DB_PASS:-}"
export SESSION_DRIVER=cookie
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export DB_TABLE_PREFIX="${DB_TABLE_PREFIX-}"

php -S "127.0.0.1:$PORT_NEW" "$ROOT/tools/parity/router-port.php" >"$WORK/port-server.log" 2>&1 &
NEW_PID=$!

LEGACY_PID=0
if [ "$LEGACY_CONTEXT" = "1" ] && [ -d "$LEGACY_DIR" ]; then
    php -S "127.0.0.1:$PORT_LEGACY" -t "$LEGACY_DIR" >"$WORK/legacy-server.log" 2>&1 &
    LEGACY_PID=$!
fi
sleep 2

# ── sessions ────────────────────────────────────────────────────────────────
scrape_token() { # jar -> CSRF token from GET /login
    curl -sS -c "$1" "$NEW_URL/login" \
        | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'
}

TOKEN="$(scrape_token "$WORK/port.jar")"
curl -sS -b "$WORK/port.jar" -c "$WORK/port.jar" \
    -d "_token=$TOKEN&loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
    "$NEW_URL/login" -o "$WORK/login.port.html"

check_page() { # jar url expected-code label
    local code
    code="$(curl -sS -b "$1" -o /dev/null -w '%{http_code}' "$2")"
    if [ "$code" != "$3" ]; then
        echo "FAIL: $4 returned HTTP $code, expected $3 (see $WORK/port-server.log)" >&2
        exit 2
    fi
}

# Sanity: every timed page must actually render (200), not bounce to login.
for spec in "/|200" "/list|200" "/past-winners/p1|200" \
            "/admin/judging/flights|200" "/admin/judging/scores|200" \
            "/admin/output/results|200"; do
    uri="${spec%%|*}"; want="${spec##*|}"
    check_page "$WORK/port.jar" "$NEW_URL$uri" "$want" "port $uri"
done

if [ "$LEGACY_PID" -ne 0 ]; then
    curl -sS -c "$WORK/legacy.jar" "$LEGACY_URL/index.php" -o /dev/null
    curl -sS -b "$WORK/legacy.jar" -c "$WORK/legacy.jar" \
        -e "$LEGACY_URL/index.php" \
        -d "loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
        "$LEGACY_URL/includes/process.inc.php?section=login&action=login" \
        -o "$WORK/login.legacy.html"
fi

# ── timing ──────────────────────────────────────────────────────────────────
mkdir -p "$WORK/samples"

declare -A CODE

timed() { # out-file jar url code-key
    local i
    for ((i = 0; i < WARMUP; i++)); do curl -sS -b "$2" -o /dev/null "$3"; done
    CODE[$4]="$(curl -sS -b "$2" -o /dev/null -w '%{http_code}' "$3")"
    for ((i = 0; i < SAMPLES; i++)); do
        curl -sS -b "$2" -o /dev/null -w '%{time_total}\n' "$3" >>"$1"
    done
}

# Login POST gets a fresh token + jar per sample (a reused session would time
# the already-authenticated path, not the real login).
timed_login() {
    local i jar token
    for ((i = 0; i < WARMUP; i++)); do
        jar="$WORK/warm-$i.jar"; rm -f "$jar"
        token="$(scrape_token "$jar")"
        curl -sS -b "$jar" -o /dev/null \
            -d "_token=$token&loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
            "$NEW_URL/login"
        rm -f "$jar"
    done
    for ((i = 0; i < SAMPLES; i++)); do
        jar="$WORK/sample-$i.jar"; rm -f "$jar"
        token="$(scrape_token "$jar")"
        CODE[login_post]="$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' \
            -d "_token=$token&loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
            "$NEW_URL/login")"
        curl -sS -b "$jar" -o /dev/null -w '%{time_total}\n' \
            -d "_token=$token&loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
            "$NEW_URL/login" >>"$WORK/samples/login_post"
        rm -f "$jar"
    done
}

# Legacy login POST (bcrypt context for the port number): fresh session per
# sample; process.inc.php demands a Referer on its own host.
timed_legacy_login() {
    local i jar
    for ((i = 0; i < SAMPLES; i++)); do
        jar="$WORK/llogin-$i.jar"; rm -f "$jar"
        curl -sS -c "$jar" "$LEGACY_URL/index.php" -o /dev/null
        CODE[l-login_post]="$(curl -sS -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' \
            -e "$LEGACY_URL/index.php" \
            -d "loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
            "$LEGACY_URL/includes/process.inc.php?section=login&action=login")"
        curl -sS -b "$jar" -c "$jar" -e "$LEGACY_URL/index.php" -o /dev/null -w '%{time_total}\n' \
            -d "loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS"             "$LEGACY_URL/includes/process.inc.php?section=login&action=login" \
            >>"$WORK/samples/l-login_post"
        rm -f "$jar"
    done
}

timed "$WORK/samples/home"            ""                       "$NEW_URL/"                        home
timed "$WORK/samples/entries_list"    "$WORK/port.jar"         "$NEW_URL/list"                    entries_list
timed "$WORK/samples/past_winners"    ""                       "$NEW_URL/past-winners/p1"         past_winners
timed "$WORK/samples/judging_flights" "$WORK/port.jar"         "$NEW_URL/admin/judging/flights"   judging_flights
timed "$WORK/samples/judging_scores"  "$WORK/port.jar"         "$NEW_URL/admin/judging/scores"    judging_scores
timed "$WORK/samples/results"         "$WORK/port.jar"         "$NEW_URL/admin/output/results"    results
timed_login

if [ "$LEGACY_PID" -ne 0 ]; then
    timed "$WORK/samples/l-home" "$WORK/legacy.jar" "$LEGACY_URL/index.php" l-home
    timed "$WORK/samples/l-list" "$WORK/legacy.jar" "$LEGACY_URL/index.php?section=list" l-list
    timed "$WORK/samples/l-past_winners" "$WORK/legacy.jar" "$LEGACY_URL/index.php?section=past-winners&go=p1" l-past_winners
    timed "$WORK/samples/l-results" "$WORK/legacy.jar" "$LEGACY_URL/includes/output.inc.php?section=results" l-results
    timed_legacy_login
fi


# ── percentiles ─────────────────────────────────────────────────────────────
pctl() { # file q → milliseconds (nearest-rank)
    sort -g "$1" | awk -v q="$2" '
        { v[++n] = $1 }
        END {
            i = int(q * n + 0.999999); if (i < 1) i = 1;
            printf "%.0f", v[i] * 1000;
        }'
}

PAGES=(home entries_list past_winners judging_flights judging_scores results login_post)

declare -A P50 P95 MISS
FAIL=0
for page in "${PAGES[@]}"; do
    f="$WORK/samples/$page"
    [ -s "$f" ] || { echo "no samples for $page" >&2; exit 2; }
    P50[$page]="$(pctl "$f" 0.50)"
    P95[$page]="$(pctl "$f" 0.95)"
    if (( $(echo "${P95[$page]} >= $THRESHOLD_MS" | bc -l) )); then
        MISS[$page]=1
        FAIL=1
    fi
done

# ── profiling misses: name the hotspot, don't just fail ────────────────────
declare -A URIs=(
    [home]=/
    [entries_list]=/list
    [past_winners]=/past-winners/p1
    [judging_flights]=/admin/judging/flights
    [judging_scores]=/admin/judging/scores
    [results]=/admin/output/results
)

PROFILE_NOTES=""
if [ "$FAIL" -eq 1 ]; then
    # Rebuild the authenticated cookie header from the session jar so the
    # in-process profiler can render authed pages.
    COOKIE="$(awk '!/^#/ && NF >= 7 { printf "%s%s=%s", sep, $6, $7; sep="; " }' "$WORK/port.jar")"
    for page in home entries_list past_winners judging_flights judging_scores results; do
        [ -n "${MISS[$page]:-}" ] || continue
        echo "== profiling miss: $page (${URIs[$page]}) =="
        NOTE="$(php "$ROOT/tools/graduation/profile_page.php" "${URIs[$page]}" "$COOKIE" 2>&1)" \
            || true
        PROFILE_NOTES+="
$NOTE"
        echo "$NOTE"
    done
    if [ -n "${MISS[login_post]:-}" ]; then
        # Login has no query hotspot worth dumping: time the raw bcrypt
        # verification against the stored hash so the report can attribute
        # the miss to deliberate password-hashing cost vs framework work.
        STORED_HASH="$("${MYSQL[@]}" -N "$DB_NAME" -e "SELECT password FROM \`$TBL\` WHERE user_name='$ADMIN_USER' LIMIT 1")"
        BCRYPT_MS="$(php -r '
            $t = hrtime(true);
            password_verify($argv[1], $argv[2]);
            printf("%.0f", (hrtime(true) - $t) / 1e6);
        ' "$ADMIN_PASS" "$STORED_HASH")"
        HASH_COST="$(php -r 'echo (int) explode("$", $argv[1])[2];' "$STORED_HASH")"
        NOTE="PROFILE /login (POST)
status: 302 | wall: ${P95[login_post]}ms (p95)
hotspot: single bcrypt password_verify at hash cost ${HASH_COST} takes ${BCRYPT_MS}ms on this host — CPU-bound key-derivation by design, not query or framework overhead. Render-path pages are unaffected."
        PROFILE_NOTES+="
$NOTE"
        echo "$NOTE"
    fi
fi

MISSED=""
for page in "${PAGES[@]}"; do
    [ -n "${MISS[$page]:-}" ] && MISSED+=" \`$page\` (${P95[$page]}ms)"
done

# ── report ──────────────────────────────────────────────────────────────────
row() { # page label legacy-sample-name-or-empty
    local page="$1" label="$2" lp="${3:-}"
    local leg="| — | —"
    if [ -n "$lp" ] && [ -s "$WORK/samples/$lp" ]; then
        local note=""
        [ "${CODE[$lp]:-200}" != "200" ] && note=" (HTTP ${CODE[$lp]})"
        leg="| $(pctl "$WORK/samples/$lp" 0.50) | $(pctl "$WORK/samples/$lp" 0.95)$note"
    fi
    local flag=""
    [ -n "${MISS[$page]:-}" ] && flag=" **MISS**"
    printf '| %s | %s | %s%s %s |\n' "$label" "${P50[$page]}" "${P95[$page]}${flag}" "$leg"
}

legacy_login_row() {
    if [ -s "$WORK/samples/l-login_post" ]; then
        printf '| login POST /login (legacy ref) | — | — | %s | %s (HTTP %s) |\n' \
            "$(pctl "$WORK/samples/l-login_post" 0.50)" \
            "$(pctl "$WORK/samples/l-login_post" 0.95)" \
            "${CODE[l-login_post]:-?}"
    fi
}

{
    echo "# Perf smoke — spec §8.5 ($(date -u +%Y-%m-%dT%H:%M:%SZ))"
    echo
    echo "- Dump: \`$(basename "$DUMP_SQL")\` ($("${MYSQL[@]}" -N "$DB_NAME" -e 'SELECT COUNT(*) FROM brewing' 2>/dev/null || echo '?') brewing rows)"
    echo "- Samples: $SAMPLES/page (+$WARMUP warm-up), port on :$PORT_NEW, php $(php -r 'echo PHP_VERSION;')"
    echo "- Gate: p95 < ${THRESHOLD_MS} ms on every port page"
    echo
    echo "| Page | port p50 (ms) | port p95 (ms) | legacy p50 (ms) | legacy p95 (ms) |"
    echo "|---|---|---|---|---|"
    row home            "home /"                          l-home
    row entries_list    "entries list /list (entrant)"    l-list
    row past_winners    "past winners /past-winners/p1"   l-past_winners
    row judging_flights "flights grid (admin)"            ""
    row judging_scores  "scores page (admin)"             ""
    row results         "results section (admin)"         l-results
    row login_post      "login POST /login"               ""
    legacy_login_row
    echo
    if [ "$FAIL" -eq 0 ]; then
        echo "## Verdict"
        echo
        echo "**PASS** — every port page served < ${THRESHOLD_MS} ms p95."
    else
        echo "## Verdict"
        echo
        echo "**MISS** — pages over ${THRESHOLD_MS} ms p95:$MISSED. See profile notes below."
    fi
    echo "$PROFILE_NOTES"
} >"$REPORT_MD"

cat "$REPORT_MD"

if [ "$FAIL" -ne 0 ]; then
    echo "PERF SMOKE FAILED: pages over ${THRESHOLD_MS}ms p95:$MISSED" >&2
    exit 1
fi

echo "PERF SMOKE PASSED: all port pages < ${THRESHOLD_MS}ms p95"
