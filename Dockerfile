# Build the Neon Auth + Realtime browser bundle.
FROM node:22-alpine AS frontend
WORKDIR /build
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY neon.ts ./
COPY src/ ./src/
COPY scripts/ ./scripts/
RUN npm run build

# Resolve production PHP dependencies separately from the runtime image.
FROM composer:2 AS php-vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# Small PHP/Apache runtime suitable for a Render Free Web Service.
FROM php:8.4-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libcurl4-openssl-dev libsqlite3-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql pdo_sqlite mbstring curl opcache \
    && a2enmod headers rewrite deflate expires \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && printf '%s\n' \
      'upload_max_filesize=210M' \
      'post_max_size=212M' \
      'max_execution_time=180' \
      'max_input_time=180' \
      'memory_limit=256M' \
      'expose_php=Off' \
      'display_errors=Off' \
      'log_errors=On' \
      'opcache.enable=1' \
      'opcache.enable_cli=0' \
      'opcache.validate_timestamps=1' \
      'opcache.revalidate_freq=2' \
      > /usr/local/etc/php/conf.d/hendy-store.ini \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
COPY --from=php-vendor /app/vendor/ /var/www/html/vendor/
COPY --from=frontend /build/js/neon-client.bundle.js /var/www/html/js/neon-client.bundle.js
COPY docker-entrypoint.sh /usr/local/bin/hendy-store-entrypoint
RUN chmod +x /usr/local/bin/hendy-store-entrypoint \
    && mkdir -p /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod 770 /var/www/html/storage

EXPOSE 10000
CMD ["hendy-store-entrypoint"]
