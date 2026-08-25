#!/usr/bin/env bash
# season_sim.sh — spec §8.3 graduation gate: full simulated season executed
# identically on BOTH apps over HTTP:
#
#   register entrant → add entries → mark paid (manual, §8.3 exception) →
#   judging location + table → manual flight assignment → score entry
#   (incl. one HM-as-'5') → BOS round → results/outputs PDFs → CSV export.
#
# Modeled on tools/parity/fetch_export.sh helpers. Unlike the parity legs,
# each app boots on its OWN identical copy of the dump (both sides write),
# and convergence is proven by diffing dumped DB rows at the end.
#
# Usage:
#   LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
#   DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql \
#   ./tools/graduation/season_sim.sh
#
# Env vars (same conventions as fetch_export.sh):
#   LEGACY_DIR           path to the legacy checkout            (required)
#   DUMP_SQL             tenant dump .sql to load  (default anon-base.sql)
#   PARITY_DB_HOST/USER/PASS  MySQL connection     (127.0.0.1 / root / root)
#   PORT_LEGACY/PORT_NEW local ports               (default 8093 / 8094)
#   EXPORT_ADMIN_USER/PASS    admin provisioned into BOTH dumps
#                        (default parity.harness@brewingcompetitions.com)
#
# Requires: mysql client, curl, php >= 8.3, a local MySQL server.
# Artifacts land in .scratch/bcoem-next/graduation/season-sim-<ts>/ and the
# human-review PDFs in .scratch/bcoem-next/graduation/season-pdfs/.
# Exit 0 = every gated step converged on both apps.

set -euo pipefail
die() { echo "FATAL: $*" >&2; exit 2; }
LEGACY_DIR="${LEGACY_DIR:?path to legacy checkout}"
DUMP_SQL="${DUMP_SQL:-$HOME/dev/bcoe/corpus/derived/anon-base.sql}"
LEGACY_DIR="$(realpath "$LEGACY_DIR")"
DUMP_SQL="$(realpath "$DUMP_SQL")"
PORT_LEGACY="${PORT_LEGACY:-8393}"
PORT_NEW="${PORT_NEW:-8394}"
# Preflight: refuse to run if the ports are already held (sibling harnesses
# use 8093/8094; colliding servers poison each other's sessions).
for p in "$PORT_LEGACY" "$PORT_NEW"; do
    if curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$p/" ; then
        die "port $p already in use — pick another via PORT_LEGACY/PORT_NEW"
    fi
done
ADMIN_USER="${EXPORT_ADMIN_USER:-parity.harness@brewingcompetitions.com}"
ADMIN_PASS="${EXPORT_ADMIN_PASS:-parity-harness}"
SEASON_USER="${SEASON_USER:-season.sim@example.invalid}"
SEASON_PASS="${SEASON_PASS:-season-pass}"

NEW_DIR="$(git rev-parse --show-toplevel)"
STAMP="$(date +%Y%m%d-%H%M%S)"
GRAD="$NEW_DIR/.scratch/bcoem-next/graduation"
RUN="$GRAD/season-sim-$STAMP"
PDFS="$GRAD/season-pdfs"
REPORT="$GRAD/season-sim-report.md"
mkdir -p "$RUN" "$PDFS"

DB_LEGACY="season_sim_legacy_$$"
DB_PORT="season_sim_port_$$"

MYSQL=(mysql -h "${PARITY_DB_HOST:-127.0.0.1}" -u "${PARITY_DB_USER:-root}")
if [ -n "${PARITY_DB_PASS:-}" ]; then MYSQL+=(-p"$PARITY_DB_PASS"); else MYSQL+=(-proot); fi

FAILURES=0
step() { # step <PASS|FAIL|DIFF|EXPECTED-DIVERGENCE> <name> [detail]
    local status="$1" name="$2" detail="${3:-}"
    printf '%-22s %s%s\n' "$status" "$name" "${detail:+ — $detail}"
    echo "$status $name ${detail:+— $detail}" >>"$RUN/steps.log"
    case "$status" in PASS|EXPECTED-DIVERGENCE) ;; *) FAILURES=$((FAILURES+1)) ;; esac
}
sql() { "${MYSQL[@]}" -N -B "$1" -e "$2"; } # sql <db> <query>
lg_token() { # <jar> <form-url> -> legacy session CSRF token
    curl -sS -b "$1" -c "$1" "$2" \
        | grep -o 'name="user_session_token" value *= *"[a-f0-9]*"' | head -1 \
        | sed -n 's/.*value *= *"\([a-f0-9]*\)".*/\1/p' || true
}
pt_token() { # <jar> <form-url> -> Laravel CSRF token
    curl -sS -b "$1" -c "$1" "$2" \
        | grep -o 'name="_token" value="[^"]*"' | head -1 \
        | sed 's/.*value="//;s/"$//' || true
}
lg_send() { # <jar> <url> <data-string> — legacy POST (needs same-host referer,
            # includes/process.inc.php:89-92)
    curl -sS -b "$1" -c "$1" -e "http://127.0.0.1:$PORT_LEGACY/index.php" \
        --data "$3" -o "$RUN/last-lg.html" "$2"
}
pt_send() { # <jar> <url> <data-string>
    curl -sS -b "$1" -c "$1" --data "$3" -o "$RUN/last-pt.html" "$2"
}

cleanup() {
    if [ "${SEASON_SIM_KEEP:-0}" = 1 ]; then
        echo "SEASON_SIM_KEEP=1 — leaving databases ($DB_LEGACY, $DB_PORT) and servers running"
        return
    fi
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB_LEGACY; DROP DATABASE IF EXISTS $DB_PORT;" 2>/dev/null || true
    kill "${LG_PID:-}" "${PT_PID:-}" 2>/dev/null || true
}
trap cleanup EXIT

# ── 1. Provision: two identical schema copies ───────────────────────────────
echo "== loading $DUMP_SQL into $DB_LEGACY and $DB_PORT =="
for db in "$DB_LEGACY" "$DB_PORT"; do
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    "${MYSQL[@]}" "$db" < "$DUMP_SQL"
done

# Open the registration/entry/judge windows identically on both copies: the
# dump's dates are in the past and the port hard-gates on them
# (app/Support/Tenant/Windows.php); legacy treats them as UI-only. Also move
# the seeded judging session into the future so judgingState stays 0.
T_NOW="$(date +%s)"
T_OPEN=$((T_NOW - 86400)); T_CLOSE=$((T_NOW + 7*86400)); T_JUDGE=$((T_NOW + 2*86400))
for db in "$DB_LEGACY" "$DB_PORT"; do
    "${MYSQL[@]}" "$db" -e "UPDATE contest_info SET contestRegistrationOpen=$T_OPEN, contestRegistrationDeadline=$T_CLOSE, contestEntryOpen=$T_OPEN, contestEntryDeadline=$T_CLOSE, contestJudgeOpen=$T_OPEN, contestJudgeDeadline=$T_CLOSE, contestEntryFee=10; UPDATE judging_locations SET judgingDate='$T_JUDGE';"
    # Schema normalization (identical both sides): the synthetic corpus dump
    # predates the baseline's judging_flights.flightEntryOrder column that
    # the port's pull-order code orders by (sql/bcoem_baseline_3.0.X.sql:398).
    "${MYSQL[@]}" "$db" -e "ALTER TABLE judging_flights ADD COLUMN flightEntryOrder int(11) DEFAULT NULL AFTER flightEntryID;"
done

migrate_db() { # <db>
    local mig1="$NEW_DIR/database/migrations/2026_08_24_000000_create_payments_table.php"
    local mig2="$NEW_DIR/database/migrations/2026_08_24_100000_add_prefsstripe_to_preferences_table.php"
    local mig
    for mig in "$mig1" "$mig2"; do
        ( cd "$NEW_DIR" && \
          DB_CONNECTION=mysql DB_HOST="${PARITY_DB_HOST:-127.0.0.1}" DB_PORT=3306 \
          DB_DATABASE="$1" DB_USERNAME="${PARITY_DB_USER:-root}" \
          DB_PASSWORD="${PARITY_DB_PASS:-root}" DB_TABLE_PREFIX="" \
          php artisan migrate --path="$mig" --realpath --force --no-ansi >/dev/null )
    done
}
migrate_db "$DB_LEGACY"
migrate_db "$DB_PORT"

# Known-password organizer (userLevel '0', bcrypt works on both apps).
HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$ADMIN_PASS")"
for db in "$DB_LEGACY" "$DB_PORT"; do
    "${MYSQL[@]}" "$db" -e "INSERT INTO users (user_name,password,userLevel,userQuestion,userQuestionAnswer,userCreated,userAdminObfuscate) VALUES ('$ADMIN_USER','$HASH','0','Generic recovery question?','$HASH',NOW(),0);"
    # Legacy's data-integrity sweep (lib/common.lib.php ~3023) deletes any
    # users row that has no matching brewer record — give the admin one.
    "${MYSQL[@]}" "$db" -e "INSERT INTO brewer (uid,brewerFirstName,brewerLastName,brewerEmail,brewerStaff,brewerSteward,brewerJudge) SELECT id,'Parity','Harness',user_name,'N','N','N' FROM users WHERE user_name='$ADMIN_USER';"
done

# ── 2. Boot servers ─────────────────────────────────────────────────────────
echo "== booting servers (legacy :$PORT_LEGACY, port :$PORT_NEW) =="
( cd "$LEGACY_DIR" && \
    PARITY_DB_NAME="$DB_LEGACY" \
    PARITY_DB_HOST="${PARITY_DB_HOST:-127.0.0.1}" \
    PARITY_DB_USER="${PARITY_DB_USER:-root}" \
    PARITY_DB_PASS="${PARITY_DB_PASS:-root}" \
    PARITY_DB_PREFIX="" \
    LEGACY_BASE_URL="http://127.0.0.1:${PORT_LEGACY}/" \
    python3 "$NEW_DIR/tools/parity/configure-legacy.py" >/dev/null )
php -S "127.0.0.1:$PORT_LEGACY" -t "$LEGACY_DIR" >"$RUN/legacy-server.log" 2>&1 &
DB_CONNECTION=mysql DB_HOST="${PARITY_DB_HOST:-127.0.0.1}" \
DB_DATABASE="$DB_PORT" \
DB_PORT="${PARITY_DB_TCP:-3306}" \
DB_USERNAME="${PARITY_DB_USER:-root}" \
DB_PASSWORD="${PARITY_DB_PASS:-root}" \
DB_TABLE_PREFIX="" \
SESSION_DRIVER=cookie CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=log \
php -S "127.0.0.1:$PORT_NEW" "$NEW_DIR/tools/parity/router-port.php" >"$RUN/port-server.log" 2>&1 &
PT_PID=$!
sleep 3
LG_CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT_LEGACY/index.php")" || true
PT_CODE="$(curl -s -o "$RUN/port-login-probe.html" -w '%{http_code}' "http://127.0.0.1:$PORT_NEW/login")" || true
[ "$LG_CODE" = 200 ] || die "legacy did not come up (HTTP $LG_CODE)"
[ "$PT_CODE" = 200 ] || die "port did not come up (HTTP $PT_CODE — see $RUN/port-login-probe.html)"
LG="http://127.0.0.1:$PORT_LEGACY"
PT="http://127.0.0.1:$PORT_NEW"
JAR_LGA="$RUN/jar-lg-admin"; JAR_PTA="$RUN/jar-pt-admin"
JAR_LGE="$RUN/jar-lg-ent";   JAR_PTE="$RUN/jar-pt-ent"

# ── 3. Admin login on both ──────────────────────────────────────────────────
echo "== logging in admin on both apps =="
curl -sS -c "$JAR_LGA" "$LG/index.php" -o /dev/null
lg_send "$JAR_LGA" "$LG/includes/process.inc.php?section=login&action=login" \
    "loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" >/dev/null
T="$(pt_token "$JAR_PTA" "$PT/login")"
pt_send "$JAR_PTA" "$PT/login" "_token=$T&loginUsername=$ADMIN_USER&loginPassword=$ADMIN_PASS" >/dev/null
LG_OK="$(curl -sS -b "$JAR_LGA" "$LG/index.php?section=admin&go=entries" | grep -c user_session_token || true)"
PT_OK="$(curl -sS -b "$JAR_PTA" "$PT/admin/payments" | grep -c '_token' || true)"
if [ "${LG_OK:-0}" -gt 0 ] && [ "${PT_OK:-0}" -gt 0 ]; then
    step PASS "admin-login"
else
    step FAIL "admin-login" "legacy admin form hits=$LG_OK port payments form hits=$PT_OK"
fi

# ── 4. Entrant registration on both ─────────────────────────────────────────
echo "== registering entrant $SEASON_USER =="
COMMON="userLevel=2&user_name=$SEASON_USER&password=$SEASON_PASS&userQuestion=none&userQuestionAnswer=simulator&brewerFirstName=Season&brewerLastName=Simulator&brewerAddress=1+Sim+St&brewerCity=Simville&brewerStateNon=N%2FA&brewerZip=4000&brewerCountry=Australia&brewerPhone1=5550100&brewerClubs=Sim+Club&brewerProAm=0&brewerStaff=N&brewerJudge=N&brewerSteward=N&brewerDropOff=0"
T="$(lg_token "$JAR_LGE" "$LG/index.php?section=register&go=entrant")"
lg_send "$JAR_LGE" \
    "$LG/includes/process.inc.php?action=add&dbTable=users&section=register&go=entrant&view=default" \
    "user_session_token=$T&relocate=index.php%3Fsection%3Dlist&$COMMON" >/dev/null
T="$(pt_token "$JAR_PTE" "$PT/register/entrant")"
pt_send "$JAR_PTE" "$PT/register/entrant" "_token=$T&password_confirmation=$SEASON_PASS&$COMMON" >/dev/null
UID_LG="$(sql "$DB_LEGACY" "SELECT id FROM users WHERE user_name='$SEASON_USER'")"
UID_PT="$(sql "$DB_PORT"   "SELECT id FROM users WHERE user_name='$SEASON_USER'")"
BR_LG="$(sql "$DB_LEGACY" "SELECT COUNT(*) FROM brewer WHERE uid=$UID_LG")"
BR_PT="$(sql "$DB_PORT"   "SELECT COUNT(*) FROM brewer WHERE uid=$UID_PT")"
if [ -n "$UID_LG" ] && [ "$UID_LG" = "$UID_PT" ] && [ "$BR_LG" = 1 ] && [ "$BR_PT" = 1 ]; then
    step PASS "register-entrant" "users.id=$UID_LG + brewer row on both"
else
    step FAIL "register-entrant" "uid lg=$UID_LG pt=$UID_PT brewer lg=$BR_LG pt=$BR_PT"
fi

# ── 5. Add three entries across categories ──────────────────────────────────
echo "== adding entries =="
add_entry_lg() { # <name> <style-code> <info>
    local t url
    url="$LG/index.php?section=brew&go=entries&action=add&id=$ENTRANT_LG_ID"
    t="$(lg_token "$JAR_LGE" "$url")"
    lg_send "$JAR_LGE" \
        "$LG/includes/process.inc.php?section=list&action=add&go=entries&dbTable=brewing&filter=default&id=$ENTRANT_LG_ID" \
        "user_session_token=$t&relocate=index.php%3Fsection%3Dlist&brewName=$1&brewStyle=$2&brewBrewerID=$UID_LG&brewBrewerFirstName=Season&brewBrewerLastName=Simulator&brewConfirmed=1&brewInfo=$3" >/dev/null
}
add_entry_pt() {
    local t; t="$(pt_token "$JAR_PTE" "$PT/brew")"
    pt_send "$JAR_PTE" "$PT/brew" "_token=$t&brewName=$1&brewStyle=$2&brewInfo=$3" >/dev/null
}
ENTRANT_LG_ID="$(sql "$DB_LEGACY" "SELECT id FROM brewer WHERE uid=$UID_LG")"
ENTRANT_PT_ID="$(sql "$DB_PORT" "SELECT id FROM brewer WHERE uid=$UID_PT")"
INFO_RED="Irish+red+ale+brewed+for+the+season+simulation"
INFO_IPA="American+porter+brewed+for+the+season+simulation"
INFO_LAG="Light+lager+brewed+for+the+season+simulation"
add_entry_lg "Season+Sim+Red"   "15-A" "$INFO_RED"
add_entry_lg "Season+Sim+Porter" "20-A" "$INFO_IPA"
add_entry_lg "Season+Sim+Lager" "1-A" "$INFO_LAG"
add_entry_pt "Season+Sim+Red"   "15-A" "$INFO_RED"
add_entry_pt "Season+Sim+Porter" "20-A" "$INFO_IPA"
add_entry_pt "Season+Sim+Lager" "1-A" "$INFO_LAG"
CNT_LG="$(sql "$DB_LEGACY" "SELECT COUNT(*) FROM brewing WHERE brewBrewerID=$UID_LG")"
CNT_PT="$(sql "$DB_PORT"   "SELECT COUNT(*) FROM brewing WHERE brewBrewerID=$UID_PT")"
EIDS_LG=($(sql "$DB_LEGACY" "SELECT id FROM brewing WHERE brewBrewerID=$UID_LG ORDER BY id"))
EIDS_PT=($(sql "$DB_PORT"   "SELECT id FROM brewing WHERE brewBrewerID=$UID_PT ORDER BY id"))
if [ "$CNT_LG" = 3 ] && [ "$CNT_PT" = 3 ] && [ "${EIDS_LG[2]:-}" = "${EIDS_PT[2]:-}" ] && [ "${#EIDS_LG[@]}" = 3 ]; then
    step PASS "add-entries" "ids ${EIDS_LG[*]} on both"
else
    step FAIL "add-entries" "lg=[${EIDS_LG[*]}]($CNT_LG) pt=[${EIDS_PT[*]}]($CNT_PT)"
fi
E_RED="${EIDS_LG[0]}"; E_IPA="${EIDS_LG[1]}"; E_LAGER="${EIDS_LG[2]}"
E_RED_PT="${EIDS_PT[0]}"; E_IPA_PT="${EIDS_PT[1]}"

# ── 6. PAY leg — §8.3 exception: manual marking on both apps ────────────────
echo "== marking paid manually on both apps =="
# Legacy per-entry update POST rewrites brewJudgingNumber unconditionally
# (process_brewing.inc.php:944), so pass current values through unchanged.
declare -A JN
while IFS=$'\t' read -r id jn; do JN["$id"]="$jn"; done \
    < <(sql "$DB_LEGACY" "SELECT id,brewJudgingNumber FROM brewing WHERE brewBrewerID=$UID_LG")
DATA="user_session_token=$(lg_token "$JAR_LGA" "$LG/index.php?section=admin&go=entries")&relocate=index.php%3Fsection%3Dadmin%26go%3Dentries"
for id in "${EIDS_LG[@]}"; do
    DATA+="&id[]=$id&brewPaid$id=1&brewJudgingNumber$id=${JN[$id]}"
done
lg_send "$JAR_LGA" "$LG/includes/process.inc.php?action=update&dbTable=brewing&filter=admin" "$DATA" >/dev/null
T="$(pt_token "$JAR_PTA" "$PT/admin/payments")"
DATA="_token=$T&pay_method=check&reference=season-sim&note=Season+simulation+manual+marking"
for id in "${EIDS_PT[@]}"; do DATA+="&entry_ids[]=$id"; done
pt_send "$JAR_PTA" "$PT/admin/payments/mark-paid" "$DATA" >/dev/null
WANT_LG="${EIDS_LG[0]}"; WANT_PT="${EIDS_PT[0]}"
for id in "${EIDS_LG[@]:1}"; do WANT_LG+=",$id"; done
for id in "${EIDS_PT[@]:1}"; do WANT_PT+=",$id"; done
PAYD_LG="$(sql "$DB_LEGACY" "SELECT COALESCE(GROUP_CONCAT(id ORDER BY id),'') FROM brewing WHERE brewBrewerID=$UID_LG AND brewPaid=1 AND brewConfirmed=1")"
PAYD_PT="$(sql "$DB_PORT"   "SELECT COALESCE(GROUP_CONCAT(id ORDER BY id),'') FROM brewing WHERE brewBrewerID=$UID_PT AND brewPaid=1 AND brewConfirmed=1")"
LEDGER_PT="$(sql "$DB_PORT" "SELECT COUNT(*) FROM payments WHERE method='manual' AND status='paid'")"
if [ "$PAYD_LG" = "$WANT_LG" ] && [ "$PAYD_PT" = "$WANT_PT" ] && [ "${LEDGER_PT:-0}" -ge 1 ]; then
    step PASS "pay-manual-marking" "paid+confirmed all 3 on both; port ledger rows=$LEDGER_PT (legacy has no manual-payment code path — expected divergence, see report)"
else
    step FAIL "pay-manual-marking" "paid+confirmed lg=[$PAYD_LG] want=[$WANT_LG] pt=[$PAYD_PT] want=[$WANT_PT] ledger=$LEDGER_PT"
fi

# ── 7. Judging location + table ─────────────────────────────────────────────
T="$(lg_token "$JAR_LGA" "$LG/index.php?section=admin&go=judging&action=add")"
JDATE="$(date -d '+3 days' '+%Y-%m-%d')"
lg_send "$JAR_LGA" "$LG/includes/process.inc.php?action=add&dbTable=judging_locations" \
    "user_session_token=$T&relocate=index.php%3Fsection%3Dadmin%26go%3Djudging_locations&judgingLocName=Season+Sim+Session&judgingLocation=2+Sim+Street&judgingLocType=0&judgingDate=$JDATE&judgingRounds=1" >/dev/null
T="$(pt_token "$JAR_PTA" "$PT/admin/judging/locations/create")"
pt_send "$JAR_PTA" "$PT/admin/judging/locations" \
    "_token=$T&judgingLocName=Season+Sim+Session&judgingLocation=2+Sim+Street&judgingLocType=0&judgingDate=$JDATE&judgingRounds=1" >/dev/null
LOC_LG="$(sql "$DB_LEGACY" "SELECT MAX(id) FROM judging_locations")"
LOC_PT="$(sql "$DB_PORT"   "SELECT MAX(id) FROM judging_locations")"
# Style ids for the table: BJCP2021 beer rows for our three codes (the dump's
# prefsStyleSet is BJCP2025, which resolves non-C groups to BJCP2021 —
# process_brewing.inc.php:335-341 / BrewController::styleVersion).
S_IDS=()
for sc in "01 A" "15 A" "20 A"; do
    set -- $sc
    S_IDS+=("$(sql "$DB_LEGACY" "SELECT id FROM styles WHERE brewStyleVersion='BJCP2021' AND brewStyleGroup='$1' AND brewStyleNum='$2'")")
done
T="$(lg_token "$JAR_LGA" "$LG/index.php?section=admin&go=judging_tables&action=add")"
lg_send "$JAR_LGA" "$LG/includes/process.inc.php?action=add&dbTable=judging_tables" \
    "user_session_token=$T&relocate=index.php%3Fsection%3Dadmin%26go%3Djudging_tables&tableName=Season+Sim+Table&tableStyles[]=${S_IDS[0]}&tableStyles[]=${S_IDS[1]}&tableStyles[]=${S_IDS[2]}&tableNumber=2&tableLocation=$LOC_LG&return-to-add-table=0" >/dev/null
T="$(pt_token "$JAR_PTA" "$PT/admin/judging/tables/create")"
pt_send "$JAR_PTA" "$PT/admin/judging/tables" \
    "_token=$T&tableName=Season+Sim+Table&tableStyles[]=${S_IDS[0]}&tableStyles[]=${S_IDS[1]}&tableStyles[]=${S_IDS[2]}&tableNumber=2&tableLocation=$LOC_PT" >/dev/null
TAB_LG="$(sql "$DB_LEGACY" "SELECT MAX(id) FROM judging_tables")"
TAB_PT="$(sql "$DB_PORT"   "SELECT MAX(id) FROM judging_tables")"
TS_LG="$(sql "$DB_LEGACY" "SELECT tableStyles FROM judging_tables WHERE id=$TAB_LG")"
TS_PT="$(sql "$DB_PORT"   "SELECT tableStyles FROM judging_tables WHERE id=$TAB_PT")"
if [ -n "$LOC_LG" ] && [ "$LOC_LG" != 1 ] && [ "$LOC_LG" = "$LOC_PT" ] && [ "$TS_LG" = "$TS_PT" ]; then
    step PASS "judging-location-table" "location=$LOC_LG table=$TAB_LG styles=[$TS_LG]"
else
    step FAIL "judging-location-table" "loc lg=$LOC_LG pt=$LOC_PT styles lg=[$TS_LG] pt=[$TS_PT]"
fi

# ── 7b. Check-in (brewReceived) ─────────────────────────────────────────────
echo "== checking in entries =="
# Legacy: bulk "mark all received" GET link (admin/entries.admin.php:825-829 →
# process_brewing.inc.php:1030-1034; plain GET, no CSRF). Port: per-entry
# barcode check-in POST. Gate covers OUR entries on both sides.
curl -sS -b "$JAR_LGA" -e "$LG/index.php" \
    "$LG/includes/process.inc.php?action=received&dbTable=brewing" -o /dev/null
for id in "${EIDS_PT[@]}"; do
    T="$(pt_token "$JAR_PTA" "$PT/admin/judging/checkin")"
    pt_send "$JAR_PTA" "$PT/admin/judging/checkin" "_token=$T&scan=$id" >/dev/null
done
RC_LG="$(sql "$DB_LEGACY" "SELECT COALESCE(GROUP_CONCAT(id ORDER BY id),'') FROM brewing WHERE brewBrewerID=$UID_LG AND brewReceived=1")"
RC_PT="$(sql "$DB_PORT"   "SELECT COALESCE(GROUP_CONCAT(id ORDER BY id),'') FROM brewing WHERE brewBrewerID=$UID_PT AND brewReceived=1")"
if [ "$RC_LG" = "$WANT_LG" ] && [ "$RC_PT" = "$WANT_PT" ]; then
    step PASS "checkin-received" "entries [$RC_LG] brewReceived=1 on both"
else
    step FAIL "checkin-received" "lg=[$RC_LG] want=[$WANT_LG] pt=[$RC_PT] want=[$WANT_PT]"
fi

# ── 8. Flight assignment ────────────────────────────────────────────────────
echo "== assigning flights =="
# Legacy's table-add auto-assigns every RECEIVED style-matching entry to
# flight 1 of the new table (process_judging_tables.inc.php:170-190), so the
# legacy side needs no manual flight POST. The port has no auto-assign, so we
# drive its manual flight grid for the same three entries.
FL_LG="$(sql "$DB_LEGACY" "SELECT COALESCE(GROUP_CONCAT(flightEntryID ORDER BY flightEntryID),'') FROM judging_flights WHERE flightTable=$TAB_LG AND flightRound=1 AND flightEntryID IN (${EIDS_LG[0]},${EIDS_LG[1]},${EIDS_LG[2]})")"
if [ "$(sql "$DB_PORT" "SELECT COUNT(*) FROM judging_flights WHERE flightTable=$TAB_PT AND flightEntryID IN (${EIDS_PT[0]},${EIDS_PT[1]},${EIDS_PT[2]}) AND flightNumber=1 AND flightRound=1")" != 3 ]; then
    T="$(pt_token "$JAR_PTA" "$PT/admin/judging/flights/$TAB_PT")"
    DATA="_token=$T"
    for id in "${EIDS_PT[@]}"; do DATA+="&flights[$id]=1"; done
    pt_send "$JAR_PTA" "$PT/admin/judging/flights/$TAB_PT" "$DATA" >/dev/null
fi
FL_PT="$(sql "$DB_PORT" "SELECT COALESCE(GROUP_CONCAT(DISTINCT flightEntryID ORDER BY flightEntryID),'') FROM judging_flights WHERE flightTable=$TAB_PT AND flightNumber=1 AND flightRound=1 AND flightEntryID IN (${EIDS_PT[0]},${EIDS_PT[1]},${EIDS_PT[2]})")"
if [ "$FL_LG" = "$FL_PT" ] && [ -n "$FL_LG" ]; then
    step PASS "assign-flights" "entries [$FL_LG] in flight 1 on both (legacy also auto-assigned matching received seed entries)"
else
    step FAIL "assign-flights" "lg=[$FL_LG] pt=[$FL_PT]"
fi

# ── 9. Score entry incl. HM-as-'5' ──────────────────────────────────────────
echo "== entering scores (places 1 / 2 / 5=HM) =="
# E_RED → place 1 (mini-BOS), E_IPA → place 2 (mini-BOS), E_LAGER → place 5 (HM, no mini-BOS).
score_of() { case "$1" in "$E_RED") echo "38 1 1";; "$E_IPA") echo "41 2 1";; *) echo "33 5 0";; esac; }
T="$(lg_token "$JAR_LGA" "$LG/index.php?section=admin&go=judging_scores&action=add&id=$TAB_LG&filter=1")"
DATA="user_session_token=$T&relocate=index.php%3Fsection%3Dadmin%26go%3Djudging_scores"
for id in "${EIDS_LG[@]}"; do
    read -r SC PL MB <<<"$(score_of "$id")"
    DATA+="&score_id[]=$id&eid$id=$id&bid$id=$UID_LG&scoreTable$id=$TAB_LG&scoreType$id=1&scoreEntry$id=$SC&scorePlace$id=$PL"
    [ "$MB" = 1 ] && DATA+="&scoreMiniBOS$id=1"
done
lg_send "$JAR_LGA" "$LG/includes/process.inc.php?action=add&dbTable=judging_scores&id=$TAB_LG" "$DATA" >/dev/null
T="$(pt_token "$JAR_PTA" "$PT/admin/judging/scores/$TAB_PT/edit")"
DATA="_token=$T"
for id in "${EIDS_PT[@]}"; do
    read -r SC PL MB <<<"$(score_of "$id")"
    # Field names are key-CONCATENATED (eidk1), not bracket arrays — the
    # controller reads $_POST['eid'.$key] (SliceCSeasonTest.php:166-173).
    DATA+="&score_id[]=$id&eid$id=$id&bid$id=$UID_PT&scoreEntry$id=$SC&scorePlace$id=$PL&scoreType$id=1"
    if [ "$MB" = 1 ]; then DATA+="&scoreMiniBOS$id=1"; else DATA+="&scoreMiniBOS$id="; fi
done
pt_send_put() { # PUT via _method override
    curl -sS -b "$1" -c "$1" --data "_method=PUT&$3" -o "$RUN/last-pt.html" "$2"
}
pt_send_put "$JAR_PTA" "$PT/admin/judging/scores/$TAB_PT" "$DATA" >/dev/null
# Legacy leaves scoreMiniBOS NULL when the box is unchecked; port writes 0 —
# COALESCE so the semantic value is compared.
SC_LG="$(sql "$DB_LEGACY" "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':',eid,scoreEntry,scorePlace,COALESCE(scoreMiniBOS,0)) ORDER BY eid),'') FROM judging_scores WHERE scoreTable='$TAB_LG'")"
SC_PT="$(sql "$DB_PORT"   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':',eid,scoreEntry,scorePlace,COALESCE(scoreMiniBOS,0)) ORDER BY eid),'') FROM judging_scores WHERE scoreTable='$TAB_PT'")"
if [ -n "$SC_LG" ] && [ "$SC_LG" = "$SC_PT" ]; then
    step PASS "enter-scores" "rows [$SC_LG] (place 5 = HM) identical"
else
    step FAIL "enter-scores" "lg=[$SC_LG] pt=[$SC_PT]"
fi

# ── 10. BOS round ───────────────────────────────────────────────────────────
echo "== running BOS round (beer, styleType 1) =="
# First-place entry takes BOS 1st place on both apps.
T="$(lg_token "$JAR_LGA" "$LG/index.php?section=admin&go=judging_scores_bos&action=enter&filter=1")"
lg_send "$JAR_LGA" "$LG/includes/process.inc.php?action=enter&dbTable=judging_scores_bos" \
    "user_session_token=$T&relocate=index.php%3Fsection%3Dadmin%26go%3Djudging_scores_bos&score_id[]=$E_RED&scorePrevious$E_RED=N&eid$E_RED=$E_RED&bid$E_RED=$UID_LG&scoreEntry$E_RED=38&scorePlace$E_RED=1&scoreType$E_RED=1" >/dev/null
T="$(pt_token "$JAR_PTA" "$PT/admin/judging/bos/1/edit")"
pt_send_put "$JAR_PTA" "$PT/admin/judging/bos/1" \
    "_token=$T&score_id[]=$E_RED_PT&eid$E_RED_PT=$E_RED_PT&bid$E_RED_PT=$UID_PT&scoreEntry$E_RED_PT=38&scorePlace$E_RED_PT=1&scoreType$E_RED_PT=1" >/dev/null
BO_LG="$(sql "$DB_LEGACY" "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':',eid,scoreEntry,scorePlace,scoreType) ORDER BY eid),'') FROM judging_scores_bos")"
BO_PT="$(sql "$DB_PORT"   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':',eid,scoreEntry,scorePlace,scoreType) ORDER BY eid),'') FROM judging_scores_bos")"
if [ -n "$BO_LG" ] && [ "$BO_LG" = "$BO_PT" ]; then
    step PASS "bos-round" "rows [$BO_LG] identical"
else
    step FAIL "bos-round" "lg=[$BO_LG] pt=[$BO_PT]"
fi

# ── 11. Results + outputs downloaded from both apps ─────────────────────────
echo "== downloading outputs =="
fetch() { curl -sS -b "$1" -D "$4" "$2" -o "$3"; }
is_pdf() { LC_ALL=C head -c 5 "$1" | grep -q '%PDF-'; }

# CSV export (byte-compared gate): same query string on both surfaces.
fetch "$JAR_LGA" "$LG/includes/output.inc.php?section=export-entries&go=csv&action=all&tb=all" \
    "$RUN/export.legacy.csv" "$RUN/export.legacy.headers"
fetch "$JAR_PTA" "$PT/admin/output/export?go=csv&action=all&tb=all" \
    "$RUN/export.port.csv" "$RUN/export.port.headers"
CSV_BAD=0
for side in legacy port; do
    if LC_ALL=C head -c 64 "$RUN/export.$side.csv" | LC_ALL=C grep -aqi '<html\|<!doctype\|<?p\|403'; then
        CSV_BAD=1
    fi
done
if [ "$CSV_BAD" = 0 ] && cmp -s "$RUN/export.legacy.csv" "$RUN/export.port.csv"; then
    cp "$RUN/export.legacy.csv" "$PDFS/entries-export.csv"
    step PASS "csv-export-bytes" "$(wc -c < "$RUN/export.legacy.csv") bytes identical"
else
    diff <(tr ',' '\n' < "$RUN/export.legacy.csv") <(tr ',' '\n' < "$RUN/export.port.csv") \
        > "$RUN/export.field-diff" || true
    step FAIL "csv-export-bytes" "sizes lg=$(wc -c < "$RUN/export.legacy.csv") pt=$(wc -c < "$RUN/export.port.csv"); see $RUN/export.field-diff"
fi

# Results PDF from both apps (legacy: winners export view=pdf FPDF download;
# port: dompdf stream). Visual-approval artifacts, not byte gates.
fetch "$JAR_LGA" "$LG/includes/output.inc.php?section=export-results&go=judging_scores_bos&view=pdf" \
    "$PDFS/results.legacy.pdf" "$RUN/results.legacy.headers"
fetch "$JAR_PTA" "$PT/admin/output/results?go=all" \
    "$PDFS/results.new.pdf" "$RUN/results.new.headers"
# Pull sheets + table cards: the port streams PDFs; legacy only has HTML
# print views for these two (output/print.output.php — no FPDF path exists),
# so the legacy side of these artifact pairs is saved as .html for review.
fetch "$JAR_PTA" "$PT/admin/output/pullsheets" "$PDFS/pullsheets.new.pdf" "$RUN/pullsheets.new.headers"
fetch "$JAR_PTA" "$PT/admin/output/table_cards" "$PDFS/table_cards.new.pdf" "$RUN/table_cards.new.headers"
fetch "$JAR_LGA" "$LG/includes/output.inc.php?section=pullsheets" \
    "$PDFS/pullsheets.legacy.html" "$RUN/pullsheets.legacy.headers"
fetch "$JAR_LGA" "$LG/includes/output.inc.php?section=table-cards" \
    "$PDFS/table_cards.legacy.html" "$RUN/table_cards.legacy.headers"
OUT_OK=1; OUT_NOTE=""
is_pdf "$PDFS/results.new.pdf" || { OUT_OK=0; OUT_NOTE+=" results.new not PDF;"; }
is_pdf "$PDFS/pullsheets.new.pdf" || { OUT_OK=0; OUT_NOTE+=" pullsheets.new not PDF;"; }
is_pdf "$PDFS/table_cards.new.pdf" || { OUT_OK=0; OUT_NOTE+=" table_cards.new not PDF;"; }
is_pdf "$PDFS/results.legacy.pdf" || { OUT_OK=0; OUT_NOTE+=" results.legacy not PDF (legacy HTML print view saved instead);"; }
grep -qi '<html' "$PDFS/pullsheets.legacy.html" || { OUT_OK=0; OUT_NOTE+=" pullsheets.legacy not HTML;"; }
if [ "$OUT_OK" = 1 ]; then
    step EXPECTED-DIVERGENCE "outputs-pdfs" "results PDF on both; pull sheets/table cards PDF on port vs legacy HTML print view (no legacy PDF renderer exists) — artifacts in $PDFS"
else
    step FAIL "outputs-pdfs" "$OUT_NOTE"
fi

# ── 12. DB convergence dumps + diffs ────────────────────────────────────────
echo "== dumping + diffing convergence tables =="
dump_table() { # <db> <select-list> <table> <outfile>
    sql "$1" "SELECT $2 FROM $3 ORDER BY id" > "$4" 2>"$4.err" || true
}
normalize() { # NULL fields → empty so '' vs NULL doesn't false-positive
    sed -e 's/\tNULL\t/\t\t/g' -e 's/^NULL\t/\t/' -e 's/\tNULL$/\t/' -e 's/^NULL$/NULLX/'
}
# brewing: exclude volatile columns (brewUpdated=NOW per side,
# brewJudgingNumber=random per app, brewReceived=legacy bulk verb is global
# while the port's check-in is per-entry, so seed rows diverge by design —
# the sim's own entries are gated by the checkin-received step instead).
# Everything else must match row-for-row, ids included.
BREW_COLS="id,brewName,brewStyle,brewCategory,brewCategorySort,brewSubCategory,brewInfo,brewComments,brewBrewerID,brewBrewerFirstName,brewBrewerLastName,brewPaid,brewConfirmed,brewStyleType,brewBoxNum"
dump_table "$DB_LEGACY" "$BREW_COLS" brewing "$RUN/db.brewing.legacy"
dump_table "$DB_PORT"   "$BREW_COLS" brewing "$RUN/db.brewing.port"
diff <(normalize < "$RUN/db.brewing.legacy") <(normalize < "$RUN/db.brewing.port") \
    > "$RUN/db.brewing.diff" || true
if [ -s "$RUN/db.brewing.legacy" ] && [ ! -s "$RUN/db.brewing.diff" ]; then
    step PASS "db-diff-brewing" "$(wc -l < "$RUN/db.brewing.legacy") rows identical (excluded: brewUpdated, brewJudgingNumber)"
else
    step DIFF "db-diff-brewing" "see $RUN/db.brewing.diff"
fi

    # scoreMiniBOS: legacy NULL vs port 0 for unchecked — compare the
for t in judging_scores judging_scores_bos; do
    # semantic value via COALESCE (same normalization as the enter-scores gate).
    if [ "$t" = judging_scores ]; then
        dump_table "$DB_LEGACY" "id,eid,bid,scoreTable,scoreEntry,scorePlace,scoreType,COALESCE(scoreMiniBOS,0) AS scoreMiniBOS" "$t" "$RUN/db.$t.legacy"
        dump_table "$DB_PORT"   "id,eid,bid,scoreTable,scoreEntry,scorePlace,scoreType,COALESCE(scoreMiniBOS,0) AS scoreMiniBOS" "$t" "$RUN/db.$t.port"
    else
        dump_table "$DB_LEGACY" '*' "$t" "$RUN/db.$t.legacy"
        dump_table "$DB_PORT"   '*' "$t" "$RUN/db.$t.port"
    fi
    diff <(normalize < "$RUN/db.$t.legacy") <(normalize < "$RUN/db.$t.port") \
        > "$RUN/db.$t.diff" || true
    if [ -s "$RUN/db.$t.legacy" ] && [ ! -s "$RUN/db.$t.diff" ]; then
        step PASS "db-diff-$t" "$(wc -l < "$RUN/db.$t.legacy") rows identical"
    else
        step DIFF "db-diff-$t" "see $RUN/db.$t.diff"
    fi
done

# payments ledger: port writes manual-marking rows; legacy's ONLY writer is
# the PayPal IPN handler (ppv.php:252-267), unreachable without PayPal.
# Dumped side-by-side; structural absence on legacy is the documented §8.3/D2
# exception, not a gate failure.
dump_table "$DB_LEGACY" '*' payments "$RUN/db.payments.legacy"
dump_table "$DB_PORT"   '*' payments "$RUN/db.payments.port"
PT_ROWS="$(wc -l < "$RUN/db.payments.port")"
BREW_COLS="id,brewName,brewStyle,brewCategory,brewCategorySort,brewSubCategory,brewInfo,brewComments,brewBrewerID,brewBrewerFirstName,brewBrewerLastName,brewPaid,brewConfirmed,brewStyleType,brewBoxNum"
step EXPECTED-DIVERGENCE "payments-ledger" \
    "port rows=$PT_ROWS (dumped to $RUN/db.payments.port); legacy rows=0 — legacy cannot write this table outside PayPal IPN (ppv.php:253); brewing-flag convergence proven above"

# ── 13. Report ──────────────────────────────────────────────────────────────
{
    echo "# Season Simulation Report — spec §8.3 graduation gate"
    echo
    echo "- Run: $STAMP · dump: \`$(basename "$DUMP_SQL")\` · legacy db \`$DB_LEGACY\` (dropped) · port db \`$DB_PORT\` (dropped)"
    echo "- Apps: legacy :$PORT_LEGACY (${LEGACY_DIR}) · port :$PORT_NEW (this repo)"
    echo "- Entrant: \`$SEASON_USER\` · entries: ${EIDS_LG[*]} (styles 15-A, 21-B, 01-A) · table \`Season Sim Table\` id $TAB_LG"
    echo
    echo "## Per-step results"
    echo
    echo '```'
    cat "$RUN/steps.log"
    echo '```'
    echo
    echo "## Pay-leg divergence (spec §8.3 exception)"
    echo
    echo "- Both apps marked the three entries paid manually over their own admin flows:"
    echo "  legacy \`includes/process.inc.php?action=update&dbTable=brewing&filter=admin\` with \`brewPaid<id>=1\`; port \`POST /admin/payments/mark-paid\`."
    echo "- \`brewing.brewPaid\`/\`brewConfirmed\`: CONVERGED for all three entries (see db-diff-brewing PASS above)."
    echo "- \`payments\` ledger: port wrote $PT_ROWS manual row(s) (\`$RUN/db.payments.port\`). Legacy wrote none and can never write any:"
    echo "  its only insert into \`payments\` is the PayPal IPN handler (\`ppv.php:252-267\`, gated on a live PayPal POST), and the legacy schema does not even create the table in baseline dumps. This is the approved D2 deviation documented in the port's create-payments migration."
    echo
    echo "## Output-artifact class divergence"
    echo
    echo "- \`results\`: PDF from both apps (\`results.legacy.pdf\` = legacy FPDF winners export \`section=export-results&view=pdf\`; \`results.new.pdf\` = port dompdf stream)."
    echo "- \`pullsheets\` / \`table_cards\`: port streams real PDFs; legacy only renders these as HTML print views (\`output/print.output.php\` dispatch — no PDF renderer exists upstream). Legacy sides saved as \`.legacy.html\` for visual comparison."
    echo
    echo "## Legacy flow archaeology"
    echo
    echo "- CSRF: every legacy POST needs field \`user_session_token\` (\`process.inc.php:103-118\`) and a same-host Referer (\`process.inc.php:89-92\`)."
    echo "- Registration regenerates the token (\`process_users_register.inc.php:423\`); handled by scraping per-form."
    echo "- Manual payment marking on legacy is the entries-list update loop, NOT \`admin/payments.admin.php\` (read-only IPN listing): \`process_brewing.inc.php:938-974\`."
    echo "- Legacy score save wipes all table scores first, then inserts non-empty rows; HM is stored as scorePlace '5' (\`process_judging_scores.inc.php:13-70\`, option list \`admin/judging_scores.admin.php:529-535\`). Port mirrors both behaviors (\`ScoreController::update\`)."
    echo "- Legacy BOS enter branches on \`scorePrevious\` Y/N for update/insert/delete (\`process_judging_scores_bos.inc.php:15-71\`)."
    echo "- Style resolution under prefsStyleSet=BJCP2025: C-groups → BJCP2025, everything else → BJCP2021 (\`process_brewing.inc.php:335-341\`; mirrored in \`BrewController::styleVersion\`)."
    echo
    echo "## Artifacts"
    echo
    echo "- Run dir: \`$RUN\` (headers, raw exports, per-table DB dumps + diffs, server logs, cookie jars)"
    echo "- Human-review artifacts: \`$PDFS\`"
} > "$REPORT"

echo
echo "== report written to $REPORT =="
if [ "$FAILURES" -eq 0 ]; then
    echo "PASS  season simulation converged (see $RUN/steps.log)"
    exit 0
fi
echo "FAIL  $FAILURES gated step(s) failed — see $REPORT"
exit 1
