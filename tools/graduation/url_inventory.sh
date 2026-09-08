#!/usr/bin/env bash
# Spec §8.1 — full URL inventory: every port GET route must respond
# (2xx/3xx, never 404/500) against a corpus dump, booted the same way as
# the parity harness (DB_TABLE_PREFIX honored; parameterized routes are
# covered by Feature tests and skipped here).
set -uo pipefail
NEW_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$NEW_DIR"
DUMP_SQL="${DUMP_SQL:?DUMP_SQL required}"; DUMP_SQL="$(realpath "$DUMP_SQL")"
PORT="${PORT:-8096}"
DB_NAME="urlinv_$$"
MYSQL=(mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u "${PARITY_DB_USER:-root}")
[ -n "${PARITY_DB_PASS:-}" ] && MYSQL+=(-p"$PARITY_DB_PASS")

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB_NAME" < "$DUMP_SQL" || exit 1

cleanup() { "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>/dev/null || true; kill ${SERVE:-} 2>/dev/null || true; }
trap cleanup EXIT

export DB_CONNECTION=mysql DB_HOST="${PARITY_DB_HOST:-127.0.0.1}" DB_PORT=3306
export DB_DATABASE="$DB_NAME" DB_USERNAME="${PARITY_DB_USER:-root}" DB_PASSWORD="${PARITY_DB_PASS:-}"
export DB_TABLE_PREFIX="${PARITY_DB_PREFIX-baseline_}"
export SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync

php -S "127.0.0.1:$PORT" tools/parity/router-port.php >/tmp/urlinv-serve.log 2>&1 &
SERVE=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.5; done

FAIL=0; TOTAL=0
while IFS='>' read -r path action; do
    [ -z "$path" ] && continue
    TOTAL=$((TOTAL+1))
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/$path")
    verdict=OK
    case "$code" in 200|301|302|303|307|308) ;; *) verdict=FAIL; FAIL=$((FAIL+1));;
    esac
    printf '%-4s %-62s %s\n' "$code" "$path" "$verdict"
done < <(php artisan route:list --json 2>/dev/null | php -r '
    $rs = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($rs as $r) {
        if (!str_contains($r["method"] ?? "", "GET")) continue;
        $u = $r["uri"] ?? "";
        if (($u[0] ?? "") === "?" || str_contains($u, "{")) continue;
        echo ltrim($u, "/"), ">", ($r["action"] ?? ""), "\n";
    }')

echo "----"
echo "RESULT: $((TOTAL-FAIL))/$TOTAL routes respond"
[ "$FAIL" -eq 0 ]
