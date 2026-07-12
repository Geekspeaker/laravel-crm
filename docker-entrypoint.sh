#!/usr/bin/env sh
# Skimify CRM (Krayin on Northflank) container entrypoint.
#
# WHY THIS EXISTS:
# The image ships a build-time .env (copied from .env.example) with an EMPTY
# APP_KEY and LOCAL db defaults. On every redeploy that stale .env came back and
# shadowed the real Northflank variables — which looked like "the database got
# reset" and forced a manual `sed` to restore APP_KEY.
#
# This script rewrites .env from the actual runtime environment on every boot, so
# config ALWAYS matches Northflank's variables and survives redeploys. The MySQL
# data lives in the managed addon and is never touched here.
set -e

echo "[entrypoint] Writing .env from runtime environment..."

cat > /app/.env <<EOF
APP_NAME=${APP_NAME:-Skimify CRM}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL}
ASSET_URL=${ASSET_URL:-${APP_URL}}
APP_TIMEZONE=${APP_TIMEZONE:-UTC}
APP_LOCALE=${APP_LOCALE:-en}
APP_CURRENCY=${APP_CURRENCY:-USD}

LOG_CHANNEL=stack
LOG_LEVEL=${LOG_LEVEL:-error}

DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
DB_PREFIX=${DB_PREFIX}
MYSQL_ATTR_SSL_CA=${MYSQL_ATTR_SSL_CA:-/etc/ssl/certs/ca-certificates.crt}

QUEUE_CONNECTION=${QUEUE_CONNECTION:-database}
CACHE_DRIVER=${CACHE_DRIVER:-file}
CACHE_STORE=${CACHE_STORE:-file}
SESSION_DRIVER=${SESSION_DRIVER:-file}
SESSION_LIFETIME=${SESSION_LIFETIME:-120}

MAIL_MAILER=${MAIL_MAILER:-log}
MAIL_HOST=${MAIL_HOST}
MAIL_PORT=${MAIL_PORT}
MAIL_USERNAME=${MAIL_USERNAME}
MAIL_PASSWORD=${MAIL_PASSWORD}
MAIL_ENCRYPTION=${MAIL_ENCRYPTION}
MAIL_FROM_ADDRESS=${MAIL_FROM_ADDRESS:-golvis@geekspeaker.com}
MAIL_FROM_NAME=${MAIL_FROM_NAME:-Skimify CRM}
EOF

# Safety net: if APP_KEY was not provided as an env var, warn loudly. We do NOT
# auto-generate here because that would rotate the key on every boot (logging
# everyone out and breaking encrypted data). Set APP_KEY as a stable Northflank
# runtime variable instead.
if [ -z "${APP_KEY}" ]; then
  echo "[entrypoint] WARNING: APP_KEY is empty. Set it as a Northflank runtime variable." >&2
fi

php artisan config:clear
php artisan package:discover --ansi || true

# Idempotent + additive: only applies pending migrations, never drops data.
# NEVER change this to migrate:fresh / migrate:refresh / db:wipe.
php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8000
