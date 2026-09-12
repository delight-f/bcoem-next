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

echo "Built: $ZIP_PATH"
