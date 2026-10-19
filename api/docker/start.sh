#!/bin/sh
# Runs migrations, then the HTTP server, a Redis queue worker and the scheduler in one container.
set -e

mkdir -p storage/framework/cache storage/framework/views storage/logs

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan db:enable-rls

# QUEUE_WORKERS (default 1) sets how many workers process pings in parallel. They take webhook alerts first.
for _ in $(seq "${QUEUE_WORKERS:-1}"); do
    (while true; do php artisan queue:work redis --queue=webhooks,ingest,default --sleep=0 --tries=3 --max-time=3600 || true; sleep 2; done) &
done
# An idle worker only waits (BLPOP) on the first queue it lists, so the workers above would leave a lone ping
# waiting up to REDIS_QUEUE_BLOCK_FOR seconds; this one waits on the ingest queue instead.
(while true; do php artisan queue:work redis --queue=ingest,default --sleep=0 --tries=3 --max-time=3600 || true; sleep 2; done) &
# Drains the spool every minute and prunes old events daily (see routes/console.php).
(while true; do php artisan schedule:work || true; sleep 2; done) &

# Octane keeps the app booted between requests (FrankenPHP worker mode), which the ingest route's latency depends on.
# OCTANE_WORKERS (default 2) caps the HTTP workers; FrankenPHP's own default is twice the host's CPU count, which can
# exceed a small instance's memory.
exec php artisan octane:frankenphp --host=0.0.0.0 --port="${PORT:-8080}" --admin-host=127.0.0.1 --workers="${OCTANE_WORKERS:-2}" --max-requests=10000
