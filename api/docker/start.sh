#!/bin/sh
# Runs migrations, then the HTTP server, a Redis queue worker and the scheduler in one container.
set -e

mkdir -p storage/framework/cache storage/framework/views storage/logs

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan db:enable-rls

# QUEUE_WORKERS (default 1) sets how many workers process pings in parallel.
for _ in $(seq "${QUEUE_WORKERS:-1}"); do
    (while true; do php artisan queue:work redis --queue=ingest,default --sleep=0 --tries=3 --max-time=3600 || true; sleep 2; done) &
done
# Drains the spool every minute and prunes old events daily (see routes/console.php).
(while true; do php artisan schedule:work || true; sleep 2; done) &

# Octane keeps the app booted between requests (FrankenPHP worker mode), which the ingest route's latency depends on.
exec php artisan octane:frankenphp --host=0.0.0.0 --port="${PORT:-8080}" --admin-host=127.0.0.1 --max-requests=10000
