# Krayin CRM — Dockerfile for Northflank (commit this at your forked repo ROOT as `Dockerfile`)
# Build type in Northflank = Dockerfile · location /Dockerfile · context / · service Port = 8000
#
# NOTE: php artisan serve is a light built-in server — fine for a small internal CRM (246 leads).
# For heavier use, switch to a php-fpm + nginx image (e.g. webdevops/php-nginx:8.2) later.

FROM php:8.2-cli

# System deps + PHP extensions Krayin/Laravel need, plus Node 20 for the Vite asset build
RUN apt-get update && apt-get install -y \
      git unzip libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
      libonig-dev libicu-dev libxml2-dev default-mysql-client curl gnupg \
 && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y nodejs \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_mysql mbstring bcmath gd zip intl exif pcntl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && npm install && npm run build \
 && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

# migrate --force is idempotent on boot. Run the one-time seed/install via Northflank shell:
#   php artisan migrate --seed
#   php artisan krayin-crm:install
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000
