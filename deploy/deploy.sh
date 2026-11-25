#!/usr/bin/env bash
#
# Zero-downtime deploy of SIAKAD on the VPS (see deploy/README.md).
#
#   /var/www/siakad/
#     releases/20261125120000/   one checkout per deploy
#     shared/.env                kept across releases (written by server-setup.sh)
#     shared/storage/            private files (photos, documents, payments), backups, logs
#     current -> releases/...    what nginx, cron and the queue worker use
#
# Usage, as the deploy user:  sudo -u www-data bash deploy.sh [branch]
#   SKIP_BACKUP=1   do not back up before migrating (not recommended)

set -euo pipefail

APP_DIR=/var/www/siakad
REPO=${REPO:-git@github.com:aknbrp-collab/siamad-me.git}
BRANCH=${1:-main}
KEEP_RELEASES=5
PHP_FPM=php8.4-fpm

if [[ $(id -un) != www-data ]]; then
    echo "Run as www-data: sudo -u www-data bash $0 $BRANCH" >&2
    exit 1
fi
if [[ ! -f $APP_DIR/shared/.env ]]; then
    echo "$APP_DIR/shared/.env is missing: run server-setup.sh first." >&2
    exit 1
fi

env_value() { grep -E "^$1=" "$APP_DIR/shared/.env" | tail -1 | cut -d= -f2- | tr -d '"' || true; }
APP_ENV=$(env_value APP_ENV)
APP_URL=$(env_value APP_URL)
FIRST_DEPLOY=0
[[ -e $APP_DIR/current ]] || FIRST_DEPLOY=1

RELEASE="$APP_DIR/releases/$(date +%Y%m%d%H%M%S)"

echo "→ Cloning $BRANCH into $RELEASE"
git clone --depth 1 --branch "$BRANCH" "$REPO" "$RELEASE"
cd "$RELEASE"

echo "→ Linking shared .env and storage"
rm -rf storage
ln -s "$APP_DIR/shared/storage" storage
ln -s "$APP_DIR/shared/.env" .env
mkdir -p storage/app/private storage/app/backups storage/framework/{cache/data,sessions,views} storage/logs

echo "→ Installing dependencies and building assets"
if [[ $APP_ENV == production ]]; then
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
else
    # The demo data seeder uses Faker, a development package.
    composer install --optimize-autoloader --no-interaction --prefer-dist
fi
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

if [[ $FIRST_DEPLOY == 0 && ${SKIP_BACKUP:-0} != 1 ]]; then
    echo "→ Backing up the database and files before migrating"
    php "$APP_DIR/current/artisan" siakad:backup
fi

echo "→ Migrating"
php artisan migrate --force

echo "→ Caching config, routes, views, events and the API document"
php artisan optimize
php -d memory_limit=1G artisan scramble:cache

echo "→ Switching current release"
ln -sfn "$RELEASE" "$APP_DIR/current.new"
mv -Tf "$APP_DIR/current.new" "$APP_DIR/current"

echo "→ Reloading PHP-FPM and restarting the queue worker"
sudo systemctl reload "$PHP_FPM"
php "$APP_DIR/current/artisan" queue:restart

echo "→ Removing old releases"
ls -1dt "$APP_DIR"/releases/* | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf

echo "→ Checking"
HOST=${APP_URL#*://}; HOST=${HOST%%/*}
if curl -fsSL -k --max-time 10 --resolve "$HOST:80:127.0.0.1" --resolve "$HOST:443:127.0.0.1" -o /dev/null "http://$HOST/up"; then
    echo "  $APP_URL/up answers."
else
    echo "  !! $APP_URL/up does not answer from this server yet (nginx not set up? see deploy/README.md step 6)."
fi
[[ -n $(env_value VAPID_PUBLIC_KEY) ]] || echo "  !! No Web Push keys yet: php artisan siakad:vapid (deploy/README.md step 8)."

echo "✓ Deployed $(git -C "$RELEASE" rev-parse --short HEAD) ($APP_ENV)"
if [[ $FIRST_DEPLOY == 1 ]]; then
    if [[ $APP_ENV == production ]]; then
        echo "  First deploy: create the first login with"
        echo "    cd $APP_DIR/current && sudo -u www-data php artisan siakad:superadmin"
    else
        echo "  First deploy (demo): load the demo data with"
        echo "    cd $APP_DIR/current && sudo -u www-data php artisan db:seed --force"
    fi
fi
