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

# Point the legacy oracle at THIS run's throwaway DB (its site/config.php
# is regenerated every run; CI's static "parity" DB is not assumed).
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
    kill "$LEGACY_PID" "$NEW_PID" 2>/dev/null || true
}
trap cleanup EXIT

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
# Authenticated roles need sessions to survive across curl requests, but
# the tenant dump has no framework tables: use file sessions.
export SESSION_DRIVER=file
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
# apps must resolve the same physical tables. Set PARITY_DB_PREFIX to the
# dump's table prefix; an empty value targets unprefixed tenant dumps
# (real-dump corpus), which is why this is `${VAR-default}` not `:-`.
export DB_TABLE_PREFIX="${PARITY_DB_PREFIX-baseline_}"
# Legacy builds absolute URLs from $base_url in site/config.php; without the
# port its redirects leave the harness server.
export LEGACY_BASE_URL="http://127.0.0.1:${PORT_LEGACY}/"
php -S "127.0.0.1:$PORT_LEGACY" -t "$LEGACY_DIR" \
    >"$REPORT/legacy-server.log" 2>&1 &
LEGACY_PID=$!
# -t public: the router's static branch serves real files from public/,
# but when it returns false the built-in server resolves against the
# docroot — without -t it uses the CWD and every Vite build asset 404s
# (pages render unstyled and picker JS never runs in harness captures).
php -S "127.0.0.1:$PORT_NEW" -t "$NEW_DIR/public" "$NEW_DIR/tools/parity/router-port.php" \
    >"$REPORT/new-server.log" 2>&1 &
NEW_PID=$!
sleep 2

# ── Role sessions ─────────────────────────────────────────────
# Login once per role on both apps; crawl reuses the cookie jars.
LEGACY_LOGIN_URL="http://127.0.0.1:$PORT_LEGACY/includes/process.inc.php?section=login&action=login"
NEW_LOGIN_URL="http://127.0.0.1:$PORT_NEW/login"

login_role() {
    local role="$1" email="$2" pass="$3"
    # Legacy: session cookie (primes prefs into the session), then POST
    # login credentials. The Referer is REQUIRED: process.inc.php drops the
    # dispatch when its host != SERVER_NAME.
    curl -s -c "$REPORT/jar-legacy-$role" "http://127.0.0.1:$PORT_LEGACY/" > /dev/null
    curl -s -b "$REPORT/jar-legacy-$role" -c "$REPORT/jar-legacy-$role" \
        -e "http://127.0.0.1:$PORT_LEGACY/" \
        -d "loginUsername=$email" -d "loginPassword=$pass" \
        "$LEGACY_LOGIN_URL" > /dev/null
    # Port: session cookie + CSRF token from the login form, then POST.
    curl -s -c "$REPORT/jar-new-$role" "$NEW_LOGIN_URL" -o "$REPORT/login-$role.html"
    local token
    token=$(grep -oE 'name="_token" value="[^"]*"' "$REPORT/login-$role.html" | head -1 | sed 's/.*value="//;s/"$//')
    curl -s -b "$REPORT/jar-new-$role" -c "$REPORT/jar-new-$role" \
        -d "_token=$token" -d "loginUsername=$email" -d "loginPassword=$pass" \
        "$NEW_LOGIN_URL" > /dev/null
}

# Verify a jar actually holds a session (login landed somewhere gated).
check_role() {
    local role="$1" probe="$2" probe_new="$3"
    local l n
    l=$(curl -s -b "$REPORT/jar-legacy-$role" -o /dev/null -w '%{redirect_url}' "http://127.0.0.1:$PORT_LEGACY/$probe")
    n=$(curl -s -b "$REPORT/jar-new-$role" -o /dev/null -w '%{redirect_url}' "http://127.0.0.1:$PORT_NEW/$probe_new")
    if [ -n "$l" ] || [ -n "$n" ]; then
        echo "WARN: $role session did not stick (legacy -> ${l:-ok}, port -> ${n:-ok})"
    fi
}

if grep -q '^entrant|' urls.txt; then
    login_role entrant "${ENTRANT_EMAIL:-jordan.oakes2@example.invalid}" "${ENTRANT_PASS:-bcoem-parity}"
    check_role entrant "index.php?section=list" "list"
fi
if grep -q '^admin|' urls.txt; then
    login_role admin "${ADMIN_EMAIL:-sam.holloway1@example.invalid}" "${ADMIN_PASS:-bcoem-parity}"
    check_role admin "index.php?section=admin" "admin"
fi

pass=0; fail=0; skipped=0
link_missing=0
noise=0
while IFS= read -r url; do
    case "$url" in ''|'#'*) skipped=$((skipped+1)); continue;; esac
    safe="$(echo "$url" | tr '/?=&|' '_____')"
    role="anon"
    case "$url" in entrant\|*) role="entrant";; admin\|*) role="admin";; esac
    url="${url#*|}"
    legacy_url="${url%%|*}"; new_url="${url#*|}"
    [ "$new_url" = "$legacy_url" ] && new_url="$legacy_url"
    legacy_url="${legacy_url#/}"; new_url="${new_url#/}"
    # output.inc.php URLs are PDF link targets (labels, pullsheets, results),
    # not crawlable HTML pages — they live in urls.txt purely as linkmap map
    # entries so the harness can translate a legacy dashboard link to its port
    # route. Fetching them here would compare two PDF binaries as text.
    # process.inc.php URLs are POST form targets (login, delete, mark-all,
    # logout): legacy GETs bounce to /?msg=98 (process.inc.php tail) — not a
    # comparable page. Same linkmap-only class as output.inc.php.
    case "$legacy_url" in *output.inc.php*|*process.inc.php*) continue;; esac
    curl -sL -b "$REPORT/jar-legacy-$role" -w '%{http_code}' "http://127.0.0.1:$PORT_LEGACY/$legacy_url" -o "$REPORT/$safe.legacy.raw" \
        > "$REPORT/$safe.legacy.code" \
        || { echo "SKIP (legacy error $(cat "$REPORT/$safe.legacy.code")) $url"; skipped=$((skipped+1)); continue; }
    curl -sL -b "$REPORT/jar-new-$role" -w '%{http_code}' "http://127.0.0.1:$PORT_NEW/$new_url" -o "$REPORT/$safe.new.raw" \
        > "$REPORT/$safe.new.code" \
        || { echo "FAIL (new error $(cat "$REPORT/$safe.new.code"))  $url"; fail=$((fail+1)); continue; }
    php normalize.php < "$REPORT/$safe.legacy.raw" > "$REPORT/$safe.legacy.clean"
    php normalize.php < "$REPORT/$safe.new.raw" > "$REPORT/$safe.new.clean"
    # Option B: content-level comparison. The standalone port is not a
    # markup transliteration, so visible text is the regression contract;
    # markup diffs are kept for triage but do not fail the gate.
    php content.php < "$REPORT/$safe.legacy.raw" > "$REPORT/$safe.legacy.text" || true
    php content.php < "$REPORT/$safe.new.raw" > "$REPORT/$safe.new.text" || true
    if diff -q "$REPORT/$safe.legacy.text" "$REPORT/$safe.new.text" >/dev/null; then
        echo "PASS $url"; pass=$((pass+1))
    else
        verdict="$(php classify.php "$REPORT/$safe.legacy.text" "$REPORT/$safe.new.text" 2>/dev/null || true)"
        case "$verdict" in VERDICT\ NOISE*)
            echo "NOISE $url"; noise=$((noise+1)); continue;;
        esac
        diff -u "$REPORT/$safe.legacy.text" "$REPORT/$safe.new.text" > "$REPORT/$safe.content-diff" || true
        diff "$REPORT/$safe.legacy.clean" "$REPORT/$safe.new.clean" > "$REPORT/$safe.markup-diff" || true
        # Link-map (DIFF pairs only — cheap): MISSING links in the port page
        # vs the legacy link graph. linkmap.php exits 1 when MISSING > 0.
        link_count=0
        if ! php linkmap.php "$REPORT/$safe.legacy.raw" "$REPORT/$safe.new.raw" "urls.txt" > "$REPORT/$safe.linkmap" 2>/dev/null; then
            link_count=$(grep -c '^MISSING' "$REPORT/$safe.linkmap" || true)
        fi
        if [ "$link_count" -gt 0 ]; then
            link_missing=$((link_missing + link_count))
        fi
        echo "DIFF $url  (content: $safe.content-diff, markup: $safe.markup-diff, missing-links: $link_count -> $safe.linkmap)"; fail=$((fail+1))
    fi
done < urls.txt

echo
echo "== parity report: $pass pass, $fail fail/diff, $noise noise, $skipped skipped, $link_missing missing links =="
echo "== artifacts: $REPORT =="
[ "$fail" -eq 0 ]
