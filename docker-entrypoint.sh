#!/usr/bin/env sh
# Geekspeaker CRM (Krayin on Northflank) container entrypoint.
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

# Northflank variables sometimes arrive wrapped in literal quotes (e.g. someone
# sets DB_PREFIX to "" meaning "empty"). A DB_PREFIX of two quote characters
# corrupts every table name (`""lead_pipeline_stages`) and breaks migrations.
# Strip stray double quotes and re-export so both .env AND the child PHP process
# see a clean value.
DB_PREFIX="$(printf '%s' "${DB_PREFIX:-}" | tr -d '"')"
export DB_PREFIX

# IMPORTANT: values are written UNQUOTED except the two that contain spaces
# (APP_NAME, MAIL_FROM_NAME). Reason: Krayin's installer (krayin-crm:install)
# parses .env with a naive explode('=') in getEnvAtRuntime() and does NOT strip
# quotes — so a quoted value like DB_PREFIX="" is read back as the literal two
# characters "" and used as the table prefix (`""lead_pipeline_stages`), which
# breaks migrations. Unquoted values keep the installer happy; the two
# space-containing values still need quotes for Laravel's own dotenv parser.
cat > /app/.env <<EOF
APP_NAME="${APP_NAME:-Geekspeaker CRM}"
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

MAIL_MAILER="${MAIL_MAILER:-log}"
MAIL_HOST="${MAIL_HOST}"
MAIL_PORT="${MAIL_PORT}"
MAIL_USERNAME="${MAIL_USERNAME}"
MAIL_PASSWORD="${MAIL_PASSWORD}"
MAIL_ENCRYPTION="${MAIL_ENCRYPTION}"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-golvis@geekspeaker.com}"
MAIL_FROM_NAME="${MAIL_FROM_NAME:-Skimify CRM}"

# Inbound email processor: IMAP (not the default 'sendgrid'). Reads the IMAP
# account from the admin Configuration -> Email -> IMAP page.
MAIL_RECEIVER_DRIVER="${MAIL_RECEIVER_DRIVER:-webklex-imap}"

IMAP_HOST="${IMAP_HOST}"
IMAP_PORT="${IMAP_PORT:-993}"
IMAP_ENCRYPTION="${IMAP_ENCRYPTION:-ssl}"
IMAP_VALIDATE_CERT="${IMAP_VALIDATE_CERT:-true}"
IMAP_USERNAME="${IMAP_USERNAME}"
IMAP_PASSWORD="${IMAP_PASSWORD}"
EOF

# Safety net: if APP_KEY was not provided as an env var, warn loudly. We do NOT
# auto-generate here because that would rotate the key on every boot (logging
# everyone out and breaking encrypted data). Set APP_KEY as a stable Northflank
# runtime variable instead.
if [ -z "${APP_KEY}" ]; then
  echo "[entrypoint] WARNING: APP_KEY is empty. Set it as a Northflank runtime variable." >&2
fi

# Ensure the Laravel storage skeleton exists. Critical when a persistent volume is
# mounted at /app/storage: a fresh volume is EMPTY and would otherwise be missing
# the framework dirs, crashing the app. Idempotent — safe on every boot.
echo "[entrypoint] Ensuring storage skeleton..."
mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/framework/testing \
  storage/logs
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
php artisan storage:link 2>/dev/null || true

php artisan config:clear
php artisan view:clear 2>/dev/null || true
php artisan package:discover --ansi || true

# Idempotent + additive: only applies pending migrations, never drops data.
# NEVER change this to migrate:fresh / migrate:refresh / db:wipe.
# Non-fatal: a migration error must NOT crash-loop the web server (that would
# also block shell access needed to inspect/repair). Serve regardless; check
# state with `php artisan migrate:status`.
php artisan migrate --force || echo "[entrypoint] WARNING: migrate --force failed; serving anyway. Inspect with 'php artisan migrate:status'."

# Background scheduler so the inbound email fetch (Schedule::command in
# routes/console.php) runs automatically. Runs alongside the web server and dies
# with the container. Output discarded to keep logs clean.
echo "[entrypoint] Starting scheduler (inbound email sync)..."
php artisan schedule:work >/dev/null 2>&1 &

exec php artisan serve --host=0.0.0.0 --port=8000
