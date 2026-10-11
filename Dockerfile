# Community-Hangar (PHP + MariaDB). Build:  docker build -t community-hangar .

# --- 1. CSS bauen (Tailwind) ---------------------------------------------------------------
FROM node:22-slim AS css
WORKDIR /app
COPY package.json package-lock.json build-css.mjs ./
RUN npm ci --no-audit --no-fund
COPY assets ./assets
COPY views ./views
COPY public/js ./public/js
RUN node build-css.mjs

# --- 2. PHP-Abhängigkeiten ------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-reqs

# --- 3. Laufzeit ----------------------------------------------------------------------------
FROM php:8.3-apache
LABEL org.opencontainers.image.licenses="LicenseRef-Proprietary" \
      org.opencontainers.image.description="Community-Hangar (Self-Hosted); Nutzungsbedingungen siehe LIZENZ.md"
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev \
 && docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod headers

COPY docker/php.ini /usr/local/etc/php/conf.d/hangar.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY views ./views
COPY migrations ./migrations
COPY bin ./bin
COPY bookmarklet ./bookmarklet
COPY LIZENZ.md LICENSE.txt ./
COPY public ./public
COPY --from=css /app/public/css ./public/css
COPY docker/entrypoint.sh /usr/local/bin/hangar-entrypoint
RUN chmod +x /usr/local/bin/hangar-entrypoint \
 && mkdir -p storage/images \
 && chown -R www-data:www-data storage

VOLUME /var/www/html/storage/images
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s CMD curl -fsS http://127.0.0.1/healthz || exit 1
ENTRYPOINT ["hangar-entrypoint"]
CMD ["apache2-foreground"]
