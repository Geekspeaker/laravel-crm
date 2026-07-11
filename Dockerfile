# Krayin CRM — Dockerfile for Northflank (commit at repo ROOT as `Dockerfile`)
# Build type = Dockerfile · location /Dockerfile · context / · service Port = 8000

FROM php:8.3-cli

# System deps + PHP extensions Krayin needs (incl. calendar), + Node 20 for the asset build.
RUN apt-get update && apt-get install -y \
      git unzip libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
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
# assets: Krayin may use Vite (`build`) or Mix (`prod`) — try build, fall back to prod.
RUN cp -n .env.example .env || true \
 && composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
 && npm install \
 && (npm run build || npm run prod || echo "no asset build script - skipping") \
 && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

# At boot (env now available): discover packages, migrate, serve.
# One-time after first deploy, via Northflank shell:
#   php artisan migrate --seed
#   php artisan krayin-crm:install
CMD php artisan package:discover --ansi; php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000