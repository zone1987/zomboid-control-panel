# syntax=docker/dockerfile:1

# --- Stage 1: build the React SPA -------------------------------------

FROM node:24-bookworm-slim AS frontend

WORKDIR /build

COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci

COPY frontend/ ./
# The build writes outside its own directory, so the target must exist.
RUN mkdir -p /backend/public && npm run build

# --- Stage 2: resolve PHP dependencies --------------------------------

FROM php:8.4-fpm-bookworm AS vendor

# ftp, sodium, curl and mbstring are hard requirements of the lock file;
# without them composer install refuses to resolve.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev libsodium-dev \
        libcurl4-openssl-dev libxml2-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql zip intl ftp sodium curl mbstring xml fileinfo \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd || true

# --- Stage 3: runtime -------------------------------------------------

FROM php:8.4-fpm-bookworm AS runtime

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    MESSENGER_WORKERS=1 \
    PHP_FPM_API_MAX_CHILDREN=12 \
    PHP_FPM_SSE_MAX_CHILDREN=8

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-fcgid supervisor \
        libpq5 libzip4 libicu72 libsodium23 libonig5 libxml2 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=vendor /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

RUN a2enmod proxy proxy_fcgi rewrite headers setenvif deflate \
    && a2dismod mpm_event \
    && a2enmod mpm_prefork \
    && rm -f /etc/apache2/sites-enabled/000-default.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php-fpm-pools.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/apache-vhost.conf /etc/apache2/sites-available/app.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# The base image ships an empty [www] pool for backwards compatibility;
# FPM refuses to start over a pool with no user, so it goes.
RUN a2ensite app \
    && rm -f /usr/local/etc/php-fpm.d/www.conf \
                /usr/local/etc/php-fpm.d/www.conf.default \
                /usr/local/etc/php-fpm.d/zz-docker.conf \
                /usr/local/etc/php-fpm.d/docker.conf \
    && mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 \
    && chmod +x /usr/local/bin/entrypoint

WORKDIR /app

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=frontend --chown=www-data:www-data /backend/public/app /app/public/app

RUN mkdir -p /app/var/cache /app/var/log \
    && chown -R www-data:www-data /app/var

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/health") ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/app.conf"]
