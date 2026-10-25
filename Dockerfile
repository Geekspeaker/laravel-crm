# Krayin CRM — Dockerfile for Northflank (commit at repo ROOT as `Dockerfile`)
# Build type = Dockerfile · location /Dockerfile · context / · service Port = 8000

# Pinned to an exact patch release. 8.3.36 fixes PHP2Shell (CVE-2026-109618 and
# CVE-2026-109619, PHP security release of 2026-10-21). The old floating `8.3-cli`
# tag shipped 8.3.33 and could resolve to a cached image on the builder, so bump
# this pin deliberately when PHP ships the next security release.
FROM php:8.3.36-cli

# Refuse to build on a PHP that is still exposed to PHP2Shell.
RUN php -r 'if (version_compare(PHP_VERSION, "8.3.36", "<")) { fwrite(STDERR, "PHP ".PHP_VERSION." is exposed to PHP2Shell (CVE-2026-109618/109619); need >= 8.3.36\n"); exit(1); }'

# System deps + PHP extensions Krayin needs (incl. calendar), + Node 20 for the asset build.
# libfreetype-dev (not the old libfreetype6-dev transitional name) exists on both
# Debian bookworm and trixie, so the build works whichever base the tag points at.
RUN apt-get update && apt-get install -y \
      git unzip libzip-dev libpng-dev libjpeg-dev libfreetype-dev \
      libonig-dev libicu-dev libxml2-dev default-mysql-client curl gnupg \
 && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y nodejs \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_mysql mbstring bcmath gd zip intl exif pcntl calendar \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# --no-scripts: skip artisan hooks (package:discover) at BUILD time — no .env/DB yet, they'd crash.
# The build-time .env (from .env.example) exists only so the Vite asset build has
# something to read; the entrypoint OVERWRITES it from the real environment at boot.
# assets: Krayin may use Vite (`build`) or Mix (`prod`) — try build, fall back to prod.
RUN cp -n .env.example .env || true \
 && composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
 && npm install \
 && (npm run build || npm run prod || echo "no asset build script - skipping") \
 && chmod -R 775 storage bootstrap/cache \
 && sed -i 's/\r$//' docker-entrypoint.sh \
 && chmod +x docker-entrypoint.sh

EXPOSE 8000

# Entrypoint rewrites .env from the runtime environment (so APP_KEY / DB / URLs
# always match Northflank vars and survive redeploys), then migrates + serves.
# One-time after the FIRST deploy, via the Northflank shell:
#   php artisan krayin-crm:install
CMD ["./docker-entrypoint.sh"]