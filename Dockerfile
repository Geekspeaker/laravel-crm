FROM php:8.2-cli

# System deps + PHP extensions Krayin needs
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

RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && npm install && npm run build \
 && chmod -R 775 storage bootstrap/cache

EXPOSE 8000
# migrate is idempotent; run once-off install/seed via Northflank shell (see checklist §5)
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000
