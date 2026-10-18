#!/bin/sh
# Runs migrations, then the HTTP server and a Redis queue worker in one container.
set -e

mkdir -p storage/framework/cache storage/framework/views storage/logs

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

(while true; do php artisan queue:work redis --sleep=0 --tries=3 --max-time=3600 || true; sleep 2; done) &

exec frankenphp php-server --root public --listen ":${PORT:-8080}"
