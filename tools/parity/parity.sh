#!/usr/bin/env bash
# Parity harness v1 — bcoem-next spec §P0.6.
#
# Boots the legacy app and the Laravel port side by side against copies of
# the SAME tenant dump, fetches each URL from urls.txt on both, normalizes,
# and reports per-URL diffs.
#
# Usage:
#   LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
#   DUMP_SQL=~/dumps/tenant1.sql \
#   ./tools/parity/parity.sh
#
# Requires: mysql client, php >= 8.3, a local MySQL server.

set -euo pipefail

LEGACY_DIR="${LEGACY_DIR:?path to legacy checkout}"
DUMP_SQL="${DUMP_SQL:?path to tenant dump .sql}"
PORT_LEGACY="${PORT_LEGACY:-8091}"
PORT_NEW="${PORT_NEW:-8092}"
DB_NAME="parity_$$"

cd "$(dirname "$0")"
NEW_DIR="$(git rev-parse --show-toplevel)"
REPORT="$NEW_DIR/tools/parity/reports/run-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$REPORT"

echo "== loading dump into $DB_NAME =="
mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u root -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u root "$DB_NAME" < "$DUMP_SQL"
trap 'mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u root -e "DROP DATABASE IF EXISTS $DB_NAME;"' EXIT

echo "== booting servers =="
php -S "127.0.0.1:$PORT_LEGACY" -t "$LEGACY_DIR" "$LEGACY_DIR/index.php" \
    >"$REPORT/legacy-server.log" 2>&1 &
LEGACY_PID=$!
php "$NEW_DIR/artisan" serve --host=127.0.0.1 --port="$PORT_NEW" \
    >"$REPORT/new-server.log" 2>&1 &
NEW_PID=$!
trap 'kill $LEGACY_PID $NEW_PID 2>/dev/null; mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u root -e "DROP DATABASE IF EXISTS $DB_NAME;"' EXIT
sleep 2

pass=0; fail=0; skipped=0
while IFS= read -r url; do
    case "$url" in ''|'#'*) skipped=$((skipped+1)); continue;; esac
    safe="$(echo "$url" | tr '/?=&' '____')"
    legacy_url="${url%%|*}"; new_url="${url#*|}"
    [ "$new_url" = "$legacy_url" ] && new_url="$legacy_url"
    curl -sfL "http://127.0.0.1:$PORT_LEGACY/$legacy_url" -o "$REPORT/$safe.legacy.raw" \
        || { echo "SKIP (legacy error) $url"; skipped=$((skipped+1)); continue; }
    curl -sf "http://127.0.0.1:$PORT_NEW/$new_url" -o "$REPORT/$safe.new.raw" \
        || { echo "FAIL (new error)   $url"; fail=$((fail+1)); continue; }
    php normalize.php < "$REPORT/$safe.legacy.raw" > "$REPORT/$safe.legacy.clean"
    php normalize.php < "$REPORT/$safe.new.raw"   > "$REPORT/$safe.new.clean"
    if diff -q "$REPORT/$safe.legacy.clean" "$REPORT/$safe.new.clean" >/dev/null; then
        echo "PASS $url"; pass=$((pass+1))
    else
        diff "$REPORT/$safe.legacy.clean" "$REPORT/$safe.new.clean" > "$REPORT/$safe.diff" || true
        echo "DIFF $url  (see $safe.diff)"; fail=$((fail+1))
    fi
done < urls.txt

echo
echo "== parity report: $pass pass, $fail fail/diff, $skipped skipped =="
echo "== artifacts: $REPORT =="
[ "$fail" -eq 0 ]
