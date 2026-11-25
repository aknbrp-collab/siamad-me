#!/usr/bin/env bash
#
# One-time setup of a fresh Ubuntu 24.04 server for SIAKAD (demo or production).
# Installs PHP 8.4, PostgreSQL 17, nginx, Supervisor, certbot, Node.js, Composer,
# optionally LibreOffice, and writes the app's .env. The app itself is installed
# afterwards with deploy.sh (see deploy/README.md).
#
# Usage, as root on the new server:
#
#   scp deploy/server-setup.sh root@<server-ip>:     (from your computer; the repo is private)
#   ssh root@<server-ip>
#   sed -i 's/\r$//' server-setup.sh
#   DOMAIN=siakad.example.ac.id bash server-setup.sh
#
# Options (environment variables):
#   DOMAIN=...              the address people open (required)
#   MODE=production|demo    demo seeds the demo data and keeps 7 days of backups (default production)
#   LIBREOFFICE=1           install LibreOffice to turn Word-template letters into PDF (needs ~4 GB RAM)
#   REPO=...                another repository; TIMEZONE=... another timezone (default Asia/Jakarta)
#
# Safe to run again: existing passwords, keys, .env and settings are kept.
# Generated passwords are written to /root/siakad-credentials.txt (root only).

set -euo pipefail

PHP=8.4
PG=17
NODE_MAJOR=22
APP_DIR=/var/www/siakad
DEPLOY_HOME=/var/www
CREDENTIALS=/root/siakad-credentials.txt
TIMEZONE=${TIMEZONE:-Asia/Jakarta}
REPO=${REPO:-git@github.com:aknbrp-collab/siamad-me.git}
MODE=${MODE:-production}
LIBREOFFICE=${LIBREOFFICE:-0}
DOMAIN=${DOMAIN:-}

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!! %s\033[0m\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo DOMAIN=... bash $0" >&2
    exit 1
fi

if [[ -z $DOMAIN ]]; then
    read -r -p "Domain people will open (e.g. siakad.mahadaly.ac.id): " DOMAIN
fi
[[ -n $DOMAIN ]] || { echo "DOMAIN is required." >&2; exit 1; }
[[ $MODE == production || $MODE == demo ]] || { echo "MODE must be production or demo." >&2; exit 1; }

if ! grep -q 'VERSION_ID="24.04"' /etc/os-release; then
    warn "This script is written for Ubuntu 24.04; this server runs: $(. /etc/os-release && echo "$PRETTY_NAME")"
    read -r -p "Continue anyway? [y/N] " answer
    [[ $answer == [yY] ]] || exit 1
fi

export DEBIAN_FRONTEND=noninteractive
RAM_MB=$(awk '/MemTotal/ {print int($2 / 1024)}' /proc/meminfo)
CODENAME=$(. /etc/os-release && echo "$VERSION_CODENAME")

# Keep generated secrets across runs.
touch "$CREDENTIALS" && chmod 600 "$CREDENTIALS"
secret() { # name → existing value from the credentials file, or a new random one
    local value
    value=$(grep -E "^$1=" "$CREDENTIALS" | cut -d= -f2- || true)
    if [[ -z $value ]]; then
        value=$(openssl rand -hex 24)
        echo "$1=$value" >> "$CREDENTIALS"
    fi
    echo "$value"
}

step "System update, timezone ($TIMEZONE), clock sync, base tools"
apt-get update -q
apt-get upgrade -yq
apt-get install -yq ca-certificates curl gnupg git unzip software-properties-common \
    ufw fail2ban unattended-upgrades acl cron
timedatectl set-timezone "$TIMEZONE"
# Signed calls to and from the CMS are refused when clocks differ by more than 5 minutes.
timedatectl set-ntp true
dpkg-reconfigure -f noninteractive unattended-upgrades

step "Swap (needed for the asset build on small servers)"
if [[ $RAM_MB -le 4096 && $(swapon --show=NAME --noheadings) != *"/swapfile"* ]]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo 'vm.swappiness=10' > /etc/sysctl.d/99-siakad-swap.conf
    sysctl -p /etc/sysctl.d/99-siakad-swap.conf
else
    echo "Skipped (${RAM_MB} MB RAM, or swap already present)."
fi

step "PHP $PHP (ondrej/php) with the required extensions"
add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq "php$PHP-fpm" "php$PHP-cli" "php$PHP-common" "php$PHP-pgsql" \
    "php$PHP-gd" "php$PHP-intl" "php$PHP-mbstring" "php$PHP-xml" "php$PHP-curl" "php$PHP-zip" \
    "php$PHP-bcmath" "php$PHP-opcache"

cat > "/etc/php/$PHP/fpm/conf.d/99-siakad.ini" <<'INI'
; SIAKAD settings (docs/SERVER-REQUIREMENTS.md). Uploads allow 10 MB (SIAKAD_UPLOAD_MAX_KB).
; To upload large backups for restoring from the web, raise both sizes and nginx's client_max_body_size.
upload_max_filesize = 12M
post_max_size = 16M
memory_limit = 256M
max_execution_time = 60
expose_php = Off
opcache.enable = 1
opcache.validate_timestamps = 0   ; deploy.sh reloads PHP-FPM
INI

# Backups and restores from the web start "php artisan ..." in a process of their own.
if grep -Eq '^disable_functions *=.*\b(exec|popen)\b' "/etc/php/$PHP/fpm/php.ini"; then
    warn "exec/popen are disabled in /etc/php/$PHP/fpm/php.ini: backups from the web will not start."
fi

# Roughly 1 PHP worker per 330 MB of RAM (docs/SERVER-REQUIREMENTS.md: 2 GB → 6, 4 GB → 12, 8 GB → 24).
MAX_CHILDREN=$(( RAM_MB / 330 )); (( MAX_CHILDREN < 4 )) && MAX_CHILDREN=4; (( MAX_CHILDREN > 48 )) && MAX_CHILDREN=48
sed -i -E "s/^pm.max_children = .*/pm.max_children = $MAX_CHILDREN/" "/etc/php/$PHP/fpm/pool.d/www.conf"
systemctl enable --now "php$PHP-fpm"
systemctl restart "php$PHP-fpm"

# Read the module list once: piping "php -m" into "grep -q" under pipefail
# reports a false failure when grep stops reading early.
modules=$(php -m)
missing=()
for extension in pgsql pdo_pgsql gd intl mbstring xml dom curl zip bcmath exif sodium openssl fileinfo; do
    grep -qix "$extension" <<< "$modules" || missing+=("$extension")
done
if (( ${#missing[@]} )); then
    warn "PHP extensions missing: ${missing[*]}"
else
    echo "All required PHP extensions are loaded."
fi

step "Composer"
if ! command -v composer >/dev/null; then
    expected=$(curl -fsSL https://composer.github.io/installer.sig)
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    actual=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
    if [[ $expected != "$actual" ]]; then
        rm -f /tmp/composer-setup.php
        echo "Composer installer checksum does not match; stopping." >&2
        exit 1
    fi
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi
COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-interaction

step "Node.js $NODE_MAJOR (NodeSource, build time only)"
if ! command -v node >/dev/null || [[ $(node -p 'process.versions.node.split(".")[0]') -lt $NODE_MAJOR ]]; then
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_MAJOR.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    apt-get update -q
    apt-get install -yq nodejs
fi
node --version

step "PostgreSQL $PG (PGDG; database \"siakad\" owned by user \"siakad\")"
# Ubuntu 24.04 ships 16; SIAKAD's backups and restores use pg_dump / pg_restore $PG.
if [[ ! -f /etc/apt/sources.list.d/pgdg.list ]]; then
    install -d -m 0755 /usr/share/postgresql-common/pgdg
    curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $CODENAME-pgdg main" > /etc/apt/sources.list.d/pgdg.list
    apt-get update -q
fi
apt-get install -yq "postgresql-$PG" "postgresql-client-$PG"
systemctl enable --now postgresql
DB_PASSWORD=$(secret DB_PASSWORD)
if [[ $(sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname = 'siakad'") != 1 ]]; then
    sudo -u postgres psql -c "CREATE USER siakad WITH PASSWORD '$DB_PASSWORD';"
fi
if [[ $(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname = 'siakad'") != 1 ]]; then
    sudo -u postgres psql -c "CREATE DATABASE siakad OWNER siakad;"
fi
# A quarter of RAM for shared_buffers, half for the planner's cache estimate.
cat > "/etc/postgresql/$PG/main/conf.d/99-siakad.conf" <<CONF
# SIAKAD settings (docs/SERVER-REQUIREMENTS.md); listens on localhost only by default.
shared_buffers = $(( RAM_MB / 4 ))MB
effective_cache_size = $(( RAM_MB / 2 ))MB
CONF
systemctl restart postgresql

if [[ $LIBREOFFICE == 1 ]]; then
    step "LibreOffice (Word-template letters as PDF)"
    apt-get install -yq libreoffice-writer-nogui fonts-dejavu fonts-liberation
fi

step "nginx, Supervisor, certbot"
apt-get install -yq nginx supervisor certbot python3-certbot-nginx
systemctl enable --now nginx supervisor cron

step "App directories and the www-data deploy user"
mkdir -p "$APP_DIR"/releases "$APP_DIR"/shared/storage/{app/private,app/backups,framework/{cache/data,sessions,views},logs}
# deploy.sh runs as www-data: it needs its own SSH key (GitHub deploy key) and caches.
# LibreOffice also keeps its profile under ~/.config.
mkdir -p "$DEPLOY_HOME"/{.ssh,.cache,.config,.npm,.composer}
chown -R www-data:www-data "$APP_DIR" "$DEPLOY_HOME"/{.ssh,.cache,.config,.npm,.composer}
chmod 700 "$DEPLOY_HOME/.ssh"
if [[ ! -f $DEPLOY_HOME/.ssh/id_ed25519 ]]; then
    sudo -u www-data ssh-keygen -t ed25519 -N '' -C "siakad-deploy@$(hostname)" -f "$DEPLOY_HOME/.ssh/id_ed25519" >/dev/null
fi
sudo -u www-data bash -c "ssh-keyscan -t ed25519 github.com >> '$DEPLOY_HOME/.ssh/known_hosts' 2>/dev/null; sort -u -o '$DEPLOY_HOME/.ssh/known_hosts' '$DEPLOY_HOME/.ssh/known_hosts'"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP-fpm" > /etc/sudoers.d/siakad-deploy
chmod 440 /etc/sudoers.d/siakad-deploy
visudo -cf /etc/sudoers.d/siakad-deploy >/dev/null
# For the mobile-app push key later (FIREBASE_CREDENTIALS=/etc/siakad/firebase.json).
install -d -m 0750 -o root -g www-data /etc/siakad

step "The app's .env ($APP_DIR/shared/.env)"
ENV_FILE=$APP_DIR/shared/.env
if [[ -f $ENV_FILE ]]; then
    echo "Kept the existing .env."
else
    if [[ $MODE == demo ]]; then APP_ENV=demo; RETENTION=7; else APP_ENV=production; RETENTION=30; fi
    if [[ $LIBREOFFICE == 1 ]]; then SOFFICE=/usr/bin/soffice; else SOFFICE=; fi
    cat > "$ENV_FILE" <<ENV
APP_NAME="SIAKAD Mahad Aly"
APP_ENV=$APP_ENV
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=$TIMEZONE
APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=siakad
DB_USERNAME=siakad
DB_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

# Password-reset emails (optional): fill in an SMTP account.
MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@$DOMAIN"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"

# Backups (docs/OPERATIONS.md §3). Put BACKUP_PATH on a separate disk in production.
BACKUP_PATH=
BACKUP_PG_DUMP=/usr/lib/postgresql/$PG/bin/pg_dump
BACKUP_PG_RESTORE=/usr/lib/postgresql/$PG/bin/pg_restore
SIAKAD_RESTORE_MAX_MB=512
BACKUP_RETENTION_DAYS=$RETENTION
BACKUP_TIME=02:00

SIAKAD_UPLOAD_MAX_KB=10240
SIAKAD_LIBREOFFICE=$SOFFICE

# Web Push: run "php artisan siakad:vapid" once after the first deploy and paste its three lines here.
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@$DOMAIN

# Mobile app push (optional): the Firebase service-account key, outside the app folder.
FIREBASE_CREDENTIALS=

# Mobile app API
SANCTUM_TOKEN_DAYS=365
API_DOCS_PUBLIC=false

# Website CMS (fill in with the CMS team; docs/OPERATIONS.md §2)
CMS_WEBHOOK_URL=
CMS_WEBHOOK_SECRET=
SIAKAD_API_KEY=
CMS_ARTICLE_TYPE=article
ENV
    chown www-data:www-data "$ENV_FILE"
    chmod 640 "$ENV_FILE"
    echo "Written ($APP_ENV, https://$DOMAIN)."
fi

step "Scheduler (cron, every minute as www-data)"
cat > /etc/cron.d/siakad <<CRON
# SIAKAD scheduler: backups, reminders, the queue, the CMS check (docs/OPERATIONS.md §2)
* * * * * www-data cd $APP_DIR/current 2>/dev/null && php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/siakad

step "Firewall (SSH, HTTP, HTTPS) and fail2ban"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
systemctl enable --now fail2ban

IP=$(curl -fsS4 --max-time 5 https://ifconfig.me 2>/dev/null || echo "<server IP>")

cat <<DONE

$(printf '\033[1;32m')Server ready ($MODE).$(printf '\033[0m') The database password is in $CREDENTIALS and already in $ENV_FILE.

Next (deploy/README.md):

 1. Add this deploy key to GitHub → repository → Settings → Deploy keys (read-only):
    $(cat "$DEPLOY_HOME/.ssh/id_ed25519.pub")

 2. Point the DNS record for $DOMAIN (an A record) at this server: $IP

 3. First deploy:
      sudo -u www-data git clone --depth 1 $REPO /tmp/siakad
      sudo -u www-data bash /tmp/siakad/deploy/deploy.sh main

 4. Then: the first account (production) or the demo data (demo), nginx + HTTPS,
    the queue worker and the Web Push keys, as in deploy/README.md steps 5–8.

Recommended: log in with an SSH key, then set "PasswordAuthentication no" in
/etc/ssh/sshd_config.d/ and run "systemctl reload ssh". Keep a second session
open while you test it.
DONE
