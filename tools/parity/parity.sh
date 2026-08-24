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
LEGACY_DIR="$(realpath "${LEGACY_DIR:?path to legacy checkout}")"
DUMP_SQL="$(realpath "$DUMP_SQL")"
PORT_LEGACY="${PORT_LEGACY:-8091}"
PORT_NEW="${PORT_NEW:-8092}"
DB_NAME="parity_$$"

cd "$(dirname "$0")"
NEW_DIR="$(git rev-parse --show-toplevel)"
REPORT="$NEW_DIR/tools/parity/reports/run-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$REPORT"

echo "== loading dump into $DB_NAME =="
MYSQL=(mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u "${PARITY_DB_USER:-root}")
[ -n "${PARITY_DB_PASS:-}" ] && MYSQL+=(-p"$PARITY_DB_PASS")

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB_NAME" < "$DUMP_SQL"
trap '"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME;"' EXIT

# The port needs an app key (encrypted redirects/sessions); provision a
# throwaway env if the checkout has none.
if [ ! -f "$NEW_DIR/.env" ]; then
    cp "$NEW_DIR/.env.example" "$NEW_DIR/.env"
    php "$NEW_DIR/artisan" key:generate --force >/dev/null
fi

echo "== booting servers =="

# The Laravel side reads its connection from env; point it at the parity DB.
export DB_CONNECTION=mysql
export DB_HOST="${PARITY_DB_HOST:-127.0.0.1}"
export DB_PORT=3306
export DB_DATABASE="$DB_NAME"
export DB_USERNAME="${PARITY_DB_USER:-root}"
export DB_PASSWORD="${PARITY_DB_PASS:-}"
# Anonymous slice needs no persistence; avoid requiring framework tables
# inside the tenant schema.
export SESSION_DRIVER=array
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
# The baseline corpus ships with the CI prefix baked into table names; both
# apps must resolve the same physical tables.
export DB_TABLE_PREFIX="${PARITY_DB_PREFIX:-baseline_}"
# Legacy builds absolute URLs from $base_url in site/config.php; without the
# port its redirects leave the harness server.
export LEGACY_BASE_URL="http://127.0.0.1:${PORT_LEGACY}/"
php -S "127.0.0.1:$PORT_LEGACY" -t "$LEGACY_DIR" "$LEGACY_DIR/index.php" \
    >"$REPORT/legacy-server.log" 2>&1 &
LEGACY_PID=$!
php -S "127.0.0.1:$PORT_NEW" "$NEW_DIR/tools/parity/router-port.php" \
    >"$REPORT/new-server.log" 2>&1 &
NEW_PID=$!
trap 'kill $LEGACY_PID $NEW_PID 2>/dev/null' EXIT
sleep 2

pass=0; fail=0; skipped=0
while IFS= read -r url; do
    case "$url" in ''|'#'*) skipped=$((skipped+1)); continue;; esac
    safe="$(echo "$url" | tr '/?=&' '____')"
    legacy_url="${url%%|*}"; new_url="${url#*|}"
    [ "$new_url" = "$legacy_url" ] && new_url="$legacy_url"
    curl -sL -w '%{http_code}' "http://127.0.0.1:$PORT_LEGACY/$legacy_url" -o "$REPORT/$safe.legacy.raw" \
        > "$REPORT/$safe.legacy.code" \
        || { echo "SKIP (legacy error $(cat "$REPORT/$safe.legacy.code")) $url"; skipped=$((skipped+1)); continue; }
    curl -sL -w '%{http_code}' "http://127.0.0.1:$PORT_NEW/$new_url" -o "$REPORT/$safe.new.raw" \
        > "$REPORT/$safe.new.code" \
        || { echo "FAIL (new error $(cat "$REPORT/$safe.new.code"))  $url"; fail=$((fail+1)); continue; }
    php normalize.php < "$REPORT/$safe.legacy.raw" > "$REPORT/$safe.legacy.clean"
    php normalize.php < "$REPORT/$safe.new.raw"   > "$REPORT/$safe.new.clean"
    # Option B: content-level comparison. The standalone port is not a
    # markup transliteration, so visible text is the regression contract;
    # markup diffs are kept for triage but do not fail the gate.
    php content.php < "$REPORT/$safe.legacy.raw" > "$REPORT/$safe.legacy.text" || true
    php content.php < "$REPORT/$safe.new.raw" > "$REPORT/$safe.new.text" || true
    if diff -q "$REPORT/$safe.legacy.text" "$REPORT/$safe.new.text" >/dev/null; then
        echo "PASS $url"; pass=$((pass+1))
    else
        diff -u "$REPORT/$safe.legacy.text" "$REPORT/$safe.new.text" > "$REPORT/$safe.content-diff" || true
        diff "$REPORT/$safe.legacy.clean" "$REPORT/$safe.new.clean" > "$REPORT/$safe.markup-diff" || true
        echo "DIFF $url  (content: $safe.content-diff, markup: $safe.markup-diff)"; fail=$((fail+1))
    fi
done < urls.txt

echo
echo "== parity report: $pass pass, $fail fail/diff, $skipped skipped =="
echo "== artifacts: $REPORT =="
[ "$fail" -eq 0 ]
