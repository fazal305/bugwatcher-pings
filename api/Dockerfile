FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql pdo_mysql opcache zip pcntl
# Render refuses to exec binaries that carry file capabilities; we listen on a high port, so it isn't needed.
RUN setcap -r /usr/local/bin/frankenphp

# Production defaults only; secrets (APP_KEY, DB_PASSWORD, ...) come from the host's environment at runtime.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.d"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache \
 && mkdir -p /usr/local/etc/php/app.d \
 && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nexpose_php=0\nmemory_limit=256M\n' > /usr/local/etc/php/app.d/app.ini \
 && chmod +x docker/start.sh

EXPOSE 8080
CMD ["/app/docker/start.sh"]
