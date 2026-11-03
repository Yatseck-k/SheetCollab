FROM node:26-bookworm-slim AS assets

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY . .
RUN npm run build

FROM php:8.5.12-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client-core \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j2 pdo_mysql \
    && a2enmod rewrite headers

COPY docker/production/apache-ports.conf /etc/apache2/ports.conf
COPY docker/production/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker/production/apache-foreground /usr/local/bin/apache-foreground
COPY docker/production/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini

RUN chmod +x /usr/local/bin/apache-foreground \
    && mkdir -p /tmp/apache2 /var/www/html/storage/app/private /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache/data /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views /var/www/html/storage/logs /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /tmp/apache2 /var/www/html/storage /var/www/html/bootstrap/cache

WORKDIR /var/www/html

FROM php-base AS dependencies

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
    && composer check-platform-reqs --no-dev

FROM php-base AS production

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=dependencies --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=dependencies --chown=www-data:www-data /var/www/html/bootstrap/cache/ ./bootstrap/cache/
COPY --from=assets --chown=www-data:www-data /build/public/build ./public/build

RUN ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APACHE_RUN_DIR=/tmp/apache2 \
    APACHE_PID_FILE=/tmp/apache2/apache2.pid \
    APACHE_LOCK_DIR=/tmp/apache2 \
    APACHE_LOG_DIR=/tmp

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/up') === false ? 1 : 0);"

CMD ["apache-foreground"]
