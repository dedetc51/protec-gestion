FROM composer:2.8.12 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress

FROM node:24.11.1-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.5-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install intl pcntl pdo_pgsql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=composer --chown=www-data:www-data /app/vendor vendor
COPY --from=assets --chown=www-data:www-data /app/public/build public/build
COPY --chown=www-data:www-data docker/php/entrypoint.sh /usr/local/bin/protec-entrypoint
RUN chmod 0555 /usr/local/bin/protec-entrypoint && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["protec-entrypoint"]
CMD ["php-fpm"]
