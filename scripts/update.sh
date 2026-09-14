#!/usr/bin/env bash
#
# In-place updater for a web-root deployment of BCOEM.
#
# The web-root artifact (bcoem-<version>-webroot.zip) is a layout whose
# document root holds index.php and the public assets, with the application
# under app-data/. This script fetches that artifact and merges it over the
# live site.
#
# It merges; it never removes site files. The two things a release must never
# overwrite are preserved explicitly: the site's app-data/.env and its
# app-data/storage directory (logs, sessions, backups).
#
# The database upgrade is NOT run here. Once the files are in place, sign in
# as a Top-Level Administrator and open /upgrade — that path takes its own
# backup and knows the correct step order. (Where the CLI php is 8.4+,
# `php app-data/artisan app:upgrade --force` is equivalent.)
#
# Usage:
#   bash scripts/update.sh [--site=DIR] [--version=X.Y.Z] [--zip-file=PATH] [--yes]
#
# With no --site, the document root is auto-detected: $HOME/public (or a
# sibling web-root name) if it looks like an install, otherwise the parent of
# the first app-data/ found under $HOME.
#
set -euo pipefail

REPO="delight-f/bcoem-next"
RELEASES_URL="https://github.com/${REPO}/releases"
LATEST_API_URL="https://api.github.com/repos/${REPO}/releases/latest"

SITE=""
VERSION=""
ZIP_FILE=""
ASSUME_YES=0

usage() {
    cat <<'EOF'
Update an in-place, web-root deployment of BCOEM to a newer release.

Usage: update.sh [options]

  --site=DIR         Document root to update (default: auto-detect).
  --version=X.Y.Z    Release to install (default: the latest release).
  --zip-file=PATH    Use a local -webroot.zip instead of downloading one.
  --yes              Do not prompt for confirmation.
  -h, --help         Show this help.

Files are merged over the site; nothing is removed. app-data/.env and
app-data/storage are preserved from the live site. Finish by opening /upgrade
as a Top-Level Administrator.
EOF
}

log() { printf '%s\n' "$*"; }
warn() { printf 'Warning: %s\n' "$*" >&2; }
die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

for arg in "$@"; do
    case "${arg}" in
        --site=*) SITE="${arg#*=}" ;;
        --version=*) VERSION="${arg#*=}" ;;
        --zip-file=*) ZIP_FILE="${arg#*=}" ;;
        --yes | -y) ASSUME_YES=1 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *) die "Unknown option: ${arg} (try --help)" ;;
    esac
done

# A terminal is only "available" if it can actually be opened. `[ -r /dev/tty ]`
# is not enough: under an asynchronous runner (no controlling terminal) the
# file exists but opening it fails, and the read below would then abort.
have_tty() {
    [ "${BCOEM_NO_TTY:-0}" -ne 1 ] || return 1
    [ -r /dev/tty ] || return 1
    { : < /dev/tty; } 2>/dev/null
}

confirm() {
    if [ "${ASSUME_YES}" -eq 1 ]; then
        return 0
    fi

    have_tty ||
        die "Confirmation needed but no terminal is attached. Re-run with --yes if you are sure."

    local answer=""
    if ! read -r -p "$1 [y/N]: " answer < /dev/tty; then
        die "Could not read from the terminal. Re-run with --yes if you are sure."
    fi

    case "${answer}" in
        y | Y | yes | YES) return 0 ;;
        *) return 1 ;;
    esac
}

# A web-root install has the front controller at the top and the application
# one level down under app-data/.
is_webroot() { [ -f "${1%/}/index.php" ] && [ -d "${1%/}/app-data" ]; }

detect_site() {
    local candidate found
    # The current directory first: running this from the document root (the
    # usual manual case) needs no guesswork, and on hosts where the web root
    # is not a child of $HOME it is the only reliable signal.
    for candidate in "$PWD" "$HOME/public" "$HOME/www" "$HOME/htdocs" "$HOME/public_html"; do
        if is_webroot "${candidate}"; then
            printf '%s' "${candidate}"
            return 0
        fi
    done

    # Fall back to the parent of the first app-data/ under the home directory.
    # Skip anything that looks like one of our own backups or staging dirs.
    found="$(find "$HOME" -maxdepth 5 -type d -name 'app-data' 2>/dev/null |
        grep -v -e '/bcoem-backup-' -e '\.old' -e '\.bcoem-' |
        head -n 1 || true)"
    if [ -n "${found}" ]; then
        dirname "${found}"
        return 0
    fi

    return 1
}

# ---------------------------------------------------------------------------
# Find and validate the site
# ---------------------------------------------------------------------------

if [ -z "${SITE}" ]; then
    SITE="$(detect_site)" || die "Could not find the site directory (tried ${PWD} and \$HOME=${HOME}). Pass --site=/path/to/docroot."
fi

SITE="${SITE%/}"
case "${SITE}" in
    "" | "/" | "${HOME}") die "Refusing to update '${SITE}'." ;;
esac

if ! is_webroot "${SITE}"; then
    die "${SITE} does not look like a web-root BCOEM install (expected index.php and app-data/). Pass --site=DIR if the document root is elsewhere."
fi

log "Site:    ${SITE}"
log "Current: $(cat "${SITE}/app-data/VERSION" 2>/dev/null || echo 'unknown')"

# ---------------------------------------------------------------------------
# Fetch the release into a staging area beside the site
# ---------------------------------------------------------------------------

STAGE="$(dirname "${SITE}")/.bcoem-update.$$"
cleanup() { rm -rf "${STAGE}"; }
trap cleanup EXIT
mkdir -p "${STAGE}"

if [ -n "${ZIP_FILE}" ]; then
    [ -f "${ZIP_FILE}" ] || die "Local zip not found: ${ZIP_FILE}"
    cp -- "${ZIP_FILE}" "${STAGE}/release.zip"
else
    command -v curl >/dev/null 2>&1 || die "curl is required to download the release."

    if [ -z "${VERSION}" ]; then
        log "Looking up the latest release…"
        json="$(curl -fsSL "${LATEST_API_URL}" 2>/dev/null)" ||
            die "Could not reach GitHub. Pass --version and --zip-file to update without it."
        tag="$(printf '%s' "${json}" | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
        VERSION="${tag#v}"
        [ -n "${VERSION}" ] || die "GitHub returned no release tag."
    fi

    url="${RELEASES_URL}/download/v${VERSION}/bcoem-${VERSION}-webroot.zip"
    log "Downloading ${url}"
    curl -fsSL "${url}" -o "${STAGE}/release.zip" || die "Download failed: ${url}"
fi

mkdir -p "${STAGE}/extract"
if command -v unzip >/dev/null 2>&1; then
    unzip -q "${STAGE}/release.zip" -d "${STAGE}/extract" || die "Could not extract the release zip."
elif command -v php >/dev/null 2>&1; then
    php -r '$z = new ZipArchive; if ($z->open($argv[1]) !== true) { exit(1); } $z->extractTo($argv[2]); $z->close();' \
        "${STAGE}/release.zip" "${STAGE}/extract" ||
        die "Could not extract the release zip (php ZipArchive failed)."
else
    die "Neither 'unzip' nor a usable 'php' is available to extract the release."
fi

NEW="$(find "${STAGE}/extract" -mindepth 1 -maxdepth 1 -type d -name 'bcoem-*-webroot' | head -n 1 || true)"
[ -n "${NEW}" ] || die "The zip did not contain a bcoem-<version>-webroot directory."
if ! is_webroot "${NEW}"; then
    die "The release zip is not a web-root artifact (no index.php + app-data/)."
fi

# A --zip-file carries the version inside app-data/VERSION.
if [ -z "${VERSION}" ]; then
    VERSION="$(tr -d '[:space:]' < "${NEW}/app-data/VERSION" 2>/dev/null || true)"
fi
[ -n "${VERSION}" ] || die "Could not determine the release version."
log "Target:  ${VERSION}"

if [ "$(cat "${SITE}/app-data/VERSION" 2>/dev/null || true)" = "${VERSION}" ]; then
    log "Already at ${VERSION}; nothing to do."
    exit 0
fi

confirm "Update ${SITE} to ${VERSION}? A file backup is taken first." ||
    die "Aborted; nothing was changed."

# ---------------------------------------------------------------------------
# Back up, then merge the new tree over the live one
# ---------------------------------------------------------------------------

BACKUP="$HOME/bcoem-backup-$(date +%Y%m%d%H%M%S)"
log "Backing up ${SITE} to ${BACKUP}…"
cp -a "${SITE}" "${BACKUP}" || die "Backup failed; nothing was changed."

# Hold the live state aside: the release ships a placeholder .env and an empty
# storage skeleton, and neither may replace what is running.
LIVE="$(mktemp -d)"
if [ -f "${SITE}/app-data/.env" ]; then
    cp -p "${SITE}/app-data/.env" "${LIVE}/env"
else
    warn "No app-data/.env found; is this a finished install?"
fi
if [ -d "${SITE}/app-data/storage" ]; then
    cp -a "${SITE}/app-data/storage" "${LIVE}/storage"
else
    warn "No app-data/storage found; the release skeleton will be used."
fi

log "Merging files…"
# Additive: files the release does not contain are left alone, exactly as an
# FTP upload of the zip contents would leave them.
if ! ( cd "${NEW}" && tar cf - . ) | ( cd "${SITE}" && tar xpf - ); then
    die "Copying the new files failed. Your backup is at ${BACKUP}."
fi

# Put the live state back.
if [ -f "${LIVE}/env" ]; then
    cp -p "${LIVE}/env" "${SITE}/app-data/.env"
fi
if [ -d "${LIVE}/storage" ]; then
    rm -rf "${SITE}/app-data/storage"
    cp -a "${LIVE}/storage" "${SITE}/app-data/storage"
fi
rm -rf "${LIVE}"

log ""
log "Files updated to ${VERSION}."
log "Backup kept at: ${BACKUP}  (delete it once you have confirmed the site works.)"
log ""
log "Last step — apply the database update:"
log "  1. Sign in as a Top-Level Administrator."
log "  2. Open https://<your-site>/upgrade and run the wizard."
log "     (It takes its own database backup and clears caches.)"
