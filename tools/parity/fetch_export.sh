#!/usr/bin/env bash
# fetch_export.sh — byte-parity leg for the entries CSV export
# (spec §7 P5.3 / §8.3, ticket .scratch/bcoem-next/issues/phase-5/03-export-csv.md).
#
# This is the one artifact class the graduation gate byte-compares: boots the
# legacy oracle and the Laravel port against copies of the SAME tenant dump,
# logs in as an admin on BOTH apps, downloads the "All Entries: All Data"
# CSV from each, and `cmp`s the raw response bodies (BOM, header row,
# quoting, ordering — everything must match byte-for-byte).
#
# Usage:
#   LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
#   DUMP_SQL=~/dumps/tenant1.sql \
#   ./tools/parity/fetch_export.sh [query-string]
#
#   query-string defaults to "go=csv&action=all&tb=all" (the admin UI's
#   "All Entries: All Data" link) and is appended verbatim to both sides:
#     legacy: includes/output.inc.php?section=export-entries&<query>
#     port:   /admin/output/export?<query>
#
# Env vars (same conventions as parity.sh):
#   LEGACY_DIR           path to the legacy checkout            (required)
#   DUMP_SQL             tenant dump .sql to load               (required)
#   PARITY_DB_HOST       MySQL host                             (default 127.0.0.1)
#   PARITY_DB_USER       MySQL user                             (default root)
#   PARITY_DB_PASS       MySQL password                         (default empty)
#   PARITY_DB_PREFIX     dump's table prefix; set to "" for unprefixed
#                        real-dump corpora                      (default baseline_)
#   PORT_LEGACY/PORT_NEW local ports                            (default 8093/8094)
#   EXPORT_ADMIN_USER    admin login on BOTH apps               (default
#                        parity.harness@brewingcompetitions.com — not a
#                        real account, so it gets PROVISIONED into the
#                        loaded dump with EXPORT_ADMIN_PASS; override with
#                        known credentials for corpus dumps)
#   EXPORT_ADMIN_PASS    its password                           (default parity-harness)
#
# Requires: mysql client, curl, php >= 8.3, a local MySQL server.
# Artifacts (both CSVs, headers, server logs) land in
# tools/parity/reports/export-<timestamp>/ for triage.

set -euo pipefail


LEGACY_DIR="${LEGACY_DIR:?path to legacy checkout}"
DUMP_SQL="${DUMP_SQL:?path to tenant dump .sql}"
LEGACY_DIR="$(realpath "$LEGACY_DIR")"
DUMP_SQL="$(realpath "$DUMP_SQL")"
PORT_LEGACY="${PORT_LEGACY:-8093}"
PORT_NEW="${PORT_NEW:-8094}"
QUERY="${1:-go=csv&action=all&tb=all}"
ADMIN_USER="${EXPORT_ADMIN_USER:-parity.harness@brewingcompetitions.com}"
ADMIN_PASS="${EXPORT_ADMIN_PASS:-parity-harness}"
DB_NAME="parity_export_$$"

cd "$(dirname "$0")"
NEW_DIR="$(git rev-parse --show-toplevel)"
REPORT="$NEW_DIR/tools/parity/reports/export-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$REPORT"

MYSQL=(mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u "${PARITY_DB_USER:-root}")
[ -n "${PARITY_DB_PASS:-}" ] && MYSQL+=(-p"$PARITY_DB_PASS")

echo "== loading $DUMP_SQL into $DB_NAME =="
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB_NAME" < "$DUMP_SQL"
CONFIGURE_LEGACY="$(pwd)/configure-legacy.py"
( cd "$LEGACY_DIR" && \
    PARITY_DB_NAME="$DB_NAME" \
    PARITY_DB_HOST="${PARITY_DB_HOST:-127.0.0.1}" \
    PARITY_DB_USER="${PARITY_DB_USER:-root}" \
    PARITY_DB_PASS="${PARITY_DB_PASS:-root}" \
    PARITY_DB_PREFIX="${PARITY_DB_PREFIX-baseline_}" \
    LEGACY_BASE_URL="http://127.0.0.1:${PORT_LEGACY}/" \
    python3 "$CONFIGURE_LEGACY" >/dev/null )

cleanup() {
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>/dev/null || true
    kill "${LEGACY_PID:-}" "${NEW_PID:-}" 2>/dev/null || true
}
trap cleanup EXIT

if [ ! -f "$NEW_DIR/.env" ]; then
    cp "$NEW_DIR/.env.example" "$NEW_DIR/.env"
    ( cd "$NEW_DIR" && php artisan key:generate >/dev/null )
fi

echo "== booting servers =="

# Same throwaway-env wiring as parity.sh: one physical schema shared by both
# apps, which is what makes row ORDER (no ORDER BY in either export query)
# comparable at all.
export DB_CONNECTION=mysql
export DB_HOST="${PARITY_DB_HOST:-127.0.0.1}"
export DB_PORT=3306
export DB_DATABASE="$DB_NAME"
export DB_USERNAME="${PARITY_DB_USER:-root}"
export DB_PASSWORD="${PARITY_DB_PASS:-}"
# Cookie (not array) sessions: the harness logs in and the session must
# survive across curl invocations.
export SESSION_DRIVER=cookie
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export DB_TABLE_PREFIX="${PARITY_DB_PREFIX-baseline_}"

# Legacy is booted WITHOUT a router script here (unlike parity.sh): the
# export URL is a direct-file hit on includes/output.inc.php, and a router
# would swallow it into index.php's HTML shell.
php -S "127.0.0.1:$PORT_LEGACY" -t "$LEGACY_DIR" >"$REPORT/legacy-server.log" 2>&1 &
LEGACY_PID=$!
php -S "127.0.0.1:$PORT_NEW" "$NEW_DIR/tools/parity/router-port.php" \
    >"$REPORT/new-server.log" 2>&1 &
NEW_PID=$!
sleep 2

echo "== provisioning admin if needed =="
# Corpus dumps rarely have a known-password admin. The export reads only the
# brewing/brewer/judging tables, so adding a users row cannot change the
# artifact's bytes. Both apps accept bcrypt hashes for login.
PREFIX="${DB_TABLE_PREFIX}"
HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$ADMIN_PASS")"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO \`${PREFIX}users\` (user_name,password,userLevel,userCreated,userAdminObfuscate) SELECT '$ADMIN_USER','$HASH','0','2024-01-01 00:00:01',0 WHERE NOT EXISTS (SELECT 1 FROM \`${PREFIX}users\` WHERE user_name='$ADMIN_USER');"

echo "== logging in as $ADMIN_USER on both apps =="

# Legacy: process.inc.php requires a Referer on its own host plus a warmed
# session (it checks $_SESSION['prefs…']); visit / first, then POST with -e.
curl -sS -c "$REPORT/cookies.legacy" "http://127.0.0.1:$PORT_LEGACY/index.php" -o /dev/null
curl -sS -b "$REPORT/cookies.legacy" -c "$REPORT/cookies.legacy" \
    -e "http://127.0.0.1:$PORT_LEGACY/index.php" \
    -d "loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
    "http://127.0.0.1:$PORT_LEGACY/includes/process.inc.php?section=login&action=login" \
    -o "$REPORT/login.legacy.html"

# Port: Laravel CSRF — pull the _token off the login form first.
TOKEN="$(curl -sS -c "$REPORT/cookies.port" "http://127.0.0.1:$PORT_NEW/login" \
    | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')"
curl -sS -b "$REPORT/cookies.port" -c "$REPORT/cookies.port" \
    -d "_token=$TOKEN&loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" \
    "http://127.0.0.1:$PORT_NEW/login" \
    -o "$REPORT/login.port.html"

fetch () { # $1 jar $2 url $3 out-body $4 out-headers
    curl -sS -b "$1" -D "$4" "$2" -o "$3"
}

echo "== fetching export (both sides) =="
LEGACY_URL="http://127.0.0.1:$PORT_LEGACY/includes/output.inc.php?section=export-entries&$QUERY"
NEW_URL="http://127.0.0.1:$PORT_NEW/admin/output/export?$QUERY"

fetch "$REPORT/cookies.legacy" "$LEGACY_URL" "$REPORT/export.legacy.csv" "$REPORT/export.legacy.headers"
fetch "$REPORT/cookies.port" "$NEW_URL" "$REPORT/export.port.csv" "$REPORT/export.port.headers"

for side in legacy port; do
    # A login redirect or 403 comes back as HTML; the real artifact starts
    # with the UTF-8 BOM. (LC_ALL=C keeps grep from choking on the BOM.)
    if LC_ALL=C head -c 64 "$REPORT/export.$side.csv" | LC_ALL=C grep -aqi '<html\|<!doctype\|<?p\|403'; then
        echo "FAIL ($side returned HTML — login failed or wrong URL)"
        echo "  see $REPORT/export.$side.csv and $REPORT/login.$side.html"
        exit 1
    fi
done

if cmp "$REPORT/export.legacy.csv" "$REPORT/export.port.csv"; then
    bytes="$(wc -c < "$REPORT/export.legacy.csv")"
    echo "PASS  export byte-parity ($bytes bytes, identical)"
    echo "legacy: $LEGACY_URL"
    echo "port:   $NEW_URL"
else
    echo "DIFF  export differs:"
    diff <(tr ',' '\n' < "$REPORT/export.legacy.csv") \
         <(tr ',' '\n' < "$REPORT/export.port.csv") > "$REPORT/export.field-diff" || true
    echo "  byte sizes: legacy=$(wc -c < "$REPORT/export.legacy.csv") port=$(wc -c < "$REPORT/export.port.csv")"
    echo "  field-level diff: $REPORT/export.field-diff"
    exit 1
fi
