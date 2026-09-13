#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:?Usage: release.sh <version> e.g. release.sh 4.0.0}"
BUILD_DIR="build/output/bcoem-${VERSION}"
ZIP_PATH="build/output/bcoem-${VERSION}.zip"

rm -rf "$BUILD_DIR" "$ZIP_PATH"
mkdir -p "$BUILD_DIR"

rsync -a \
  --exclude=.git \
  --exclude=.github \
  --exclude=node_modules \
  --exclude=tests \
  --exclude=.env \
  --exclude=storage/logs/*.log \
  --exclude=storage/framework/views/* \
  --exclude=storage/framework/cache/* \
  --exclude=storage/framework/sessions/* \
  --exclude=bootstrap/cache/*.php \
  --exclude=public/hot \
  --exclude=.scratch \
  --exclude=.slop-scan.cache.json \
  --exclude=.phpunit.result.cache \
  --exclude=.commandcode \
  --exclude=build \
  ./ "$BUILD_DIR/"

cd "$BUILD_DIR"
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
rm -rf node_modules

# composer install's post-autoload-dump (`php artisan package:discover`)
# regenerates these after the rsync exclude ran, so remove them here too.
rm -f bootstrap/cache/*.php

# Bare-upload bootstrap. The zip ships no .env, so a fresh upload would boot
# with an empty APP_KEY and the database-backed session/cache/queue drivers,
# whose tables come from the skipped framework 0001_* migrations — GET /install
# would 500 instead of showing the wizard. These values are enough to boot the
# installer; InstallationService::install() overwrites this file with the real
# configuration (DB credentials, APP_URL, a fresh APP_KEY) on first run. No DB_*
# or other credential is written.
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
cat > .env <<EOF
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
LOG_CHANNEL=stack
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
EOF

echo "$VERSION" > VERSION

cd -
cd build/output
zip -r "bcoem-${VERSION}.zip" "bcoem-${VERSION}" -x "*.DS_Store"
cd -

# --- Web-root-deployable artifact -------------------------------------------
# The same built tree, rearranged so its contents can be uploaded straight into
# a web root: public's contents become the document root and everything else
# moves under app-data/ (inside the docroot, for hosts that cannot write above
# it). Derived from the tree already assembled above, so every file is carried
# over and nothing has to be enumerated by hand.
WEB_DIR="build/output/bcoem-${VERSION}-webroot"
WEB_ZIP="build/output/bcoem-${VERSION}-webroot.zip"
rm -rf "${WEB_DIR}" "${WEB_ZIP}"
mkdir -p "${WEB_DIR}/app-data"

# dotglob so dotfiles (.env, .htaccess, .env.example) move as well.
( shopt -s dotglob nullglob && mv "${BUILD_DIR}"/* "${WEB_DIR}/app-data/" )
( shopt -s dotglob nullglob && mv "${WEB_DIR}/app-data/public"/* "${WEB_DIR}/" )
rmdir "${WEB_DIR}/app-data/public"
rm -rf "${BUILD_DIR}"

# The packaged front controller points at app-data/ and sets the public path;
# the source repo's public/index.php is intentionally left alone.
cp build/webroot/index.php "${WEB_DIR}/index.php"
cp build/webroot/app-data.htaccess "${WEB_DIR}/app-data/.htaccess"

cd build/output
zip -qr "bcoem-${VERSION}-webroot.zip" "bcoem-${VERSION}-webroot" -x "*.DS_Store"
cd -

echo "Built: $ZIP_PATH"
echo "Built: $WEB_ZIP"
