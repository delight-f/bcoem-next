#!/usr/bin/env bash
#
# BCOEM one-shot installer for a VPS you already have SSH on.
#
# Typical use:
#   curl -sSL https://get.yourapp.com/install.sh | bash
#
# The hosting of that short URL (and of the release assets it points at) is
# separate infrastructure, decided elsewhere; this repository only owns the
# script itself.
#
# This script installs nothing itself. It downloads a release zip and then
# drives the application's own CLI commands, `php artisan app:install` and
# `php artisan app:upgrade`, which are the only places install/upgrade logic
# lives. No database, backup or migration logic belongs in this file.
#
set -euo pipefail

REPO="delight-f/bcoem-next"
RELEASES_URL="https://github.com/${REPO}/releases"
LATEST_API_URL="https://api.github.com/repos/${REPO}/releases/latest"

usage() {
    cat <<'EOF'
Install or upgrade BCOEM on this server.

Usage: install.sh [options]

Target and release:
  --target=DIR            Directory to install into (default: /var/www/bcoem)
  --version=X.Y.Z         Release version to fetch (default: the latest release)
  --zip-file=PATH         Use a local release zip instead of downloading one.

Database and site details (prompted for when omitted):
  --db-host=HOST          Database host (default: 127.0.0.1)
  --db-port=PORT          Database port (default: 3306)
  --db-name=NAME          Database name
  --db-username=USER      Database username
  --db-password=PASS      Database password
  --app-url=URL           Public site URL
  --admin-name=NAME       Administrator's name
  --admin-email=EMAIL     Administrator's email
  --admin-password=PASS   Administrator's password

Behaviour:
  --yes                   Assume yes for confirmations (upgrade and unknown files)
  --no-cron               Do not add the Laravel scheduler cron entry
  -h, --help              Show this help

The site details are only used on a fresh install; an upgrade reuses the
existing .env and takes a database backup automatically.
EOF
}

log() { printf '%s\n' "$*"; }
warn() { printf 'Warning: %s\n' "$*" >&2; }
die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

TARGET="/var/www/bcoem"
VERSION=""
ZIP_FILE=""
ASSUME_YES=0
NO_CRON=0

DB_HOST=""
DB_PORT=""
DB_NAME=""
DB_USERNAME=""
DB_PASSWORD=""
DB_PASSWORD_SET=0
APP_URL=""
ADMIN_NAME=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""

for arg in "$@"; do
    case "${arg}" in
        --target=*) TARGET="${arg#*=}" ;;
        --version=*) VERSION="${arg#*=}" ;;
        --zip-file=*) ZIP_FILE="${arg#*=}" ;;
        --db-host=*) DB_HOST="${arg#*=}" ;;
        --db-port=*) DB_PORT="${arg#*=}" ;;
        --db-name=*) DB_NAME="${arg#*=}" ;;
        --db-username=*) DB_USERNAME="${arg#*=}" ;;
        --db-password=*)
            DB_PASSWORD="${arg#*=}"
            DB_PASSWORD_SET=1
            ;;
        --app-url=*) APP_URL="${arg#*=}" ;;
        --admin-name=*) ADMIN_NAME="${arg#*=}" ;;
        --admin-email=*) ADMIN_EMAIL="${arg#*=}" ;;
        --admin-password=*) ADMIN_PASSWORD="${arg#*=}" ;;
        --yes | -y) ASSUME_YES=1 ;;
        --no-cron) NO_CRON=1 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *) die "Unknown option: ${arg} (try --help)" ;;
    esac
done

TARGET="${TARGET%/}"
if [ -z "${TARGET}" ] || [ "${TARGET}" = "/" ]; then
    die "Refusing to use '${TARGET}' as the target directory."
fi

# Prompts are read from the terminal, so `curl | bash` still works. Set
# BCOEM_NO_TTY=1 in automation to force non-interactive mode.
have_tty() { [ "${BCOEM_NO_TTY:-0}" -ne 1 ] && [ -r /dev/tty ]; }

ask() {
    # ask <variable> <prompt> [default]
    local var="$1" prompt="$2" default="${3:-}" answer
    have_tty || return 1
    read -r -p "${prompt}${default:+ [${default}]}: " answer < /dev/tty
    printf -v "${var}" '%s' "${answer:-${default}}"
}

ask_secret() {
    # ask_secret <variable> <prompt>
    local var="$1" prompt="$2" answer
    have_tty || return 1
    read -r -s -p "${prompt}: " answer < /dev/tty
    printf '\n'
    printf -v "${var}" '%s' "${answer}"
}

confirm() {
    [ "${ASSUME_YES}" -eq 1 ] && return 0
    have_tty ||
        die "Confirmation needed but no terminal is available; re-run with --yes if you are sure."
    local answer
    read -r -p "$1 [y/N]: " answer < /dev/tty
    case "${answer}" in
        y | Y | yes | YES) return 0 ;;
        *) return 1 ;;
    esac
}

tree_is_installed() { [ -f "$1/artisan" ] && [ -f "$1/.env" ]; }

dir_has_entries() { [ -e "$1" ] && [ -n "$(ls -A "$1" 2>/dev/null || true)" ]; }

# ---------------------------------------------------------------------------
# Environment checks (fail fast)
# ---------------------------------------------------------------------------

command -v php >/dev/null 2>&1 || die "PHP is not installed or not on your PATH."
command -v unzip >/dev/null 2>&1 || die "The 'unzip' command is not installed."
log "PHP $(php -r 'echo PHP_VERSION;') found."

if dir_has_entries "${TARGET}"; then
    [ -w "${TARGET}" ] || die "No write access to ${TARGET}."
else
    parent="$(dirname "${TARGET}")"
    [ -d "${parent}" ] || mkdir -p "${parent}" 2>/dev/null || true
    [ -w "${parent}" ] || die "No write access to ${parent} (needed to create ${TARGET})."
fi

# ---------------------------------------------------------------------------
# Staging area, alongside the target so the final move is a rename
# ---------------------------------------------------------------------------

STAGE="$(dirname "${TARGET}")/.bcoem-install.$$"
mkdir -p "${STAGE}"
cleanup() { rm -rf "${STAGE}"; }
trap cleanup EXIT

fetch_release_zip() {
    # fetch_release_zip <destination-zip>
    local zip="$1"
    if [ -n "${ZIP_FILE}" ]; then
        [ -f "${ZIP_FILE}" ] || die "Local zip not found: ${ZIP_FILE}"
        cp -- "${ZIP_FILE}" "${zip}"
        if [ -z "${VERSION}" ]; then
            # Only the release's own top-level VERSION, not a vendored one.
            local version_entry
            version_entry="$(unzip -Z1 "${ZIP_FILE}" 2>/dev/null | grep -E '^[^/]+/VERSION$' | head -n 1 || true)"
            if [ -n "${version_entry}" ]; then
                VERSION="$(unzip -p "${ZIP_FILE}" "${version_entry}" | tr -d '[:space:]')"
            fi
        fi
        return
    fi

    command -v curl >/dev/null 2>&1 || die "curl is required to download the release."

    if [ -z "${VERSION}" ]; then
        log "Looking up the latest release…"
        local json
        json="$(curl -fsSL "${LATEST_API_URL}" 2>/dev/null)" ||
            die "Could not fetch the latest release from GitHub (check this server's network access, and that a release has been published). Pass --version and --zip-file to install without it."
        VERSION="$(printf '%s' "${json}" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo ltrim((string) ($j["tag_name"] ?? ""), "v");')"
        [ -n "${VERSION}" ] || die "GitHub returned no release tag for the latest release."
    fi

    local url="${RELEASES_URL}/download/v${VERSION}/bcoem-${VERSION}.zip"
    log "Downloading ${url}"
    curl -fsSL "${url}" -o "${zip}" || die "Download failed: ${url}"
}

extract_app() {
    # extract_app <zip> <extract-dir>; prints the app root
    local zip="$1" dest="$2" app
    mkdir -p "${dest}"
    unzip -q "${zip}" -d "${dest}" || die "Could not extract the release zip."
    app="$(find "${dest}" -mindepth 1 -maxdepth 1 -type d -name "bcoem-*" | head -n 1 || true)"
    if [ -n "${app}" ] && [ -f "${app}/artisan" ]; then
        printf '%s' "${app}"
    else
        printf '%s' "${dest}"
    fi
}

run_php_check() {
    # run_php_check <app-root>
    local app="$1"
    if [ -f "${app}/scripts/php-check.php" ]; then
        php "${app}/scripts/php-check.php" "${app}"
    elif command -v composer >/dev/null 2>&1; then
        (cd "${app}" && composer check-platform-reqs --no-dev)
    else
        die "The release has no scripts/php-check.php and Composer is not installed."
    fi
}

collect_install_input() {
    local missing=""
    [ -n "${DB_HOST}" ] || DB_HOST="127.0.0.1"
    [ -n "${DB_PORT}" ] || DB_PORT="3306"
    [ -z "${DB_NAME}" ] && ask DB_NAME "Database name" || true
    [ -z "${DB_USERNAME}" ] && ask DB_USERNAME "Database username" || true
    if [ "${DB_PASSWORD_SET}" -eq 0 ]; then
        ask_secret DB_PASSWORD "Database password" || true
    fi
    [ -z "${APP_URL}" ] && ask APP_URL "Site URL (e.g. https://beer.example.com)" || true
    [ -z "${ADMIN_NAME}" ] && ask ADMIN_NAME "Administrator name" || true
    [ -z "${ADMIN_EMAIL}" ] && ask ADMIN_EMAIL "Administrator email" || true
    [ -z "${ADMIN_PASSWORD}" ] && ask_secret ADMIN_PASSWORD "Administrator password" || true

    local missing="" name value
    for name in DB_NAME DB_USERNAME APP_URL ADMIN_NAME ADMIN_EMAIL ADMIN_PASSWORD; do
        eval "value=\${${name}}"
        [ -n "${value}" ] || missing="${missing} ${name}"
    done
    [ "${DB_PASSWORD_SET}" -eq 1 ] || [ -n "${DB_PASSWORD}" ] || missing="${missing} DB_PASSWORD"

    [ -z "${missing}" ] ||
        die "Missing required value(s):${missing}. Pass them as options or run from a terminal."
}

run_artisan() {
    # run_artisan <app-root> <args...>
    local app="$1"
    shift
    (cd "${app}" && php artisan "$@")
}

install_cron() {
    if [ "${NO_CRON}" -eq 1 ]; then
        log "Skipping the scheduler cron entry (--no-cron)."
        return
    fi
    if ! command -v crontab >/dev/null 2>&1; then
        warn "crontab not found; add this yourself: * * * * * cd ${TARGET} && php artisan schedule:run >> /dev/null 2>&1"
        return
    fi

    local existing entry
    existing="$(crontab -l 2>/dev/null || true)"
    if printf '%s' "${existing}" | grep -Fq "${TARGET}" &&
        printf '%s' "${existing}" | grep -Fq 'schedule:run'; then
        log "Scheduler cron entry already present."
        return
    fi

    entry="* * * * * cd ${TARGET} && php artisan schedule:run >> /dev/null 2>&1"
    printf '%s\n%s\n' "${existing}" "${entry}" | crontab - ||
        warn "Could not update crontab; add this yourself: ${entry}"
    log "Added the Laravel scheduler cron entry."
}

# ---------------------------------------------------------------------------
# Fresh install
# ---------------------------------------------------------------------------

fresh_install() {
    mkdir -p "${TARGET}"
    if dir_has_entries "${TARGET}"; then
        confirm "Directory ${TARGET} contains files but is not a recognizable BCOEM install. Extract into it anyway?" ||
            die "Aborted; nothing was changed."
    fi

    local app
    fetch_release_zip "${STAGE}/release.zip"
    app="$(extract_app "${STAGE}/release.zip" "${STAGE}/extract")"

    log "Checking this server meets the requirements…"
    run_php_check "${app}" || die "This server does not meet the requirements; nothing was installed."
    collect_install_input

    log "Extracting into ${TARGET}…"
    (shopt -s dotglob nullglob && mv "${app}"/* "${TARGET}/")

    log "Running the installer…"
    # Credentials are passed on the command line because app:install's flags are
    # the supported non-interactive interface; run this on a host you trust.
    run_artisan "${TARGET}" app:install --no-interaction \
        --db-host="${DB_HOST}" \
        --db-port="${DB_PORT}" \
        --db-name="${DB_NAME}" \
        --db-username="${DB_USERNAME}" \
        --db-password="${DB_PASSWORD}" \
        --app-url="${APP_URL}" \
        --admin-name="${ADMIN_NAME}" \
        --admin-email="${ADMIN_EMAIL}" \
        --admin-password="${ADMIN_PASSWORD}"

    install_cron

    log ""
    log "Installed BCOEM ${VERSION} into ${TARGET}."
    log "Sign in at ${APP_URL} as ${ADMIN_EMAIL}."
}

# ---------------------------------------------------------------------------
# Upgrade
# ---------------------------------------------------------------------------

upgrade_install() {
    local live="${TARGET}"
    local current="unknown"
    [ -f "${live}/VERSION" ] && current="$(tr -d '[:space:]' < "${live}/VERSION")"

    fetch_release_zip "${STAGE}/release.zip"

    confirm "Upgrade ${live} from ${current} to ${VERSION}? A database backup is taken first and the site will be briefly unavailable. Continue?" ||
        die "Aborted; nothing was changed."

    log "Checking this server meets the requirements…"
    local app
    app="$(extract_app "${STAGE}/release.zip" "${STAGE}/extract")"
    run_php_check "${app}" || die "This server does not meet the requirements; the live install was not touched."

    # The new tree must carry the live credentials and uploaded data; the
    # release zip never contains either.
    [ -f "${live}/.env" ] && cp -p "${live}/.env" "${app}/.env"
    if [ -d "${live}/storage" ]; then
        rm -rf "${app}/storage"
        cp -a "${live}/storage" "${app}/storage"
    fi

    local timestamp backup_dir
    timestamp="$(date +%Y%m%d%H%M%S)"
    backup_dir="${live}.bak-${timestamp}"

    log "Swapping files into place…"
    mv "${live}" "${backup_dir}" || die "Could not move the live directory aside."
    if ! mv "${app}" "${live}"; then
        # The file move failing is not an application failure; put the live
        # directory back so the site is not left without files.
        mv "${backup_dir}" "${live}"
        die "Could not move the new version into place; the original directory was restored."
    fi

    log "Running the upgrade…"
    local output
    if output="$(run_artisan "${live}" app:upgrade --force --no-interaction 2>&1)"; then
        printf '%s\n' "${output}"
        log ""
        log "Upgrade complete."
        log "Your old files are kept at ${backup_dir}; delete them once you have confirmed the site works."
        return
    fi

    printf '%s\n' "${output}" >&2
    local backup_path
    backup_path="$(printf '%s' "${output}" | grep -oE '/[^ ]*pre-upgrade-[A-Za-z0-9._-]+\.sql' | head -n 1 || true)"

    log "" >&2
    log "The upgrade failed and was NOT rolled back automatically." >&2
    if [ -n "${backup_path}" ]; then
        log "Your database backup: ${backup_path}" >&2
    else
        log "The database backup location is in the output above." >&2
    fi
    log "Previous files retained at: ${backup_dir}" >&2
    log "New files are in place at: ${live}" >&2
    log "The site may be in maintenance mode until the problem is fixed. Contact support with the output above." >&2
    exit 1
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------

if tree_is_installed "${TARGET}"; then
    upgrade_install
else
    fresh_install
fi
