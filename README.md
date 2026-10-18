# BugWatcher Pings

BugWatcher Pings is a real-time error monitoring and alerting app. Your applications send error reports ("pings") to a Laravel API, which queues them on Redis. Workers parse each report, group repeats of the same error into one issue and store it, then broadcast it over WebSockets to a React dashboard with a live feed and send an alert to Slack or Discord.

**Live demo:** not deployed yet.

## Status

This is the project scaffold. Working so far:

- Laravel API with `GET /api/health`, JSON error responses (no stack traces when `APP_DEBUG=false`) and CORS limited to the web app's origin
- Configuration for PostgreSQL, a Redis queue (through Predis), and broadcasting through Laravel Reverb locally or Pusher in production
- Production Docker image for the API that runs migrations, a Redis queue worker and the web server
- React dashboard shell: landing page, app layout and an empty live feed

Planned: error ingestion, fingerprinting and grouping, the live feed over WebSockets, Slack and Discord alerts, and sign-in.

## Stack

| Part | Technology |
|---|---|
| `api/` | Laravel 13 (PHP 8.4), Laravel Sanctum, PostgreSQL, Redis queue via Predis, Laravel Reverb (local) or Pusher (production) for broadcasting, Laravel Horizon for local queue monitoring |
| `web/` | React 19, Vite, Tailwind CSS 4, React Router — plain JavaScript/JSX |

## Local setup

You need PHP 8.3+ (with `pdo_pgsql`, `mbstring`, `openssl`), Composer, Docker and Node 20+. The `redis` PHP extension is not needed.

### 1. PostgreSQL and Redis

```bash
docker compose up -d
```

This starts PostgreSQL 17 on port 5432 and Redis 7 on port 6379, with data kept in named volumes. The database credentials match `api/.env.example`. `docker-compose.yml` is for local development only.

### 2. API

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --port=8473
```

In separate terminals, as needed:

```bash
php artisan queue:work redis     # process queued jobs
php artisan reverb:start         # WebSocket server on port 8080
```

Laravel Horizon is installed as a development dependency for watching the Redis queue locally (`php artisan horizon`, dashboard at `/horizon`). It needs the `pcntl` and `posix` extensions, so it does not run on Windows outside WSL or Docker. It is not used in production.

### 3. Web app

```bash
cd web
npm install
cp .env.example .env.local
npm run dev                 # http://localhost:5473
```

### Tests and checks

```bash
cd api && php artisan test && ./vendor/bin/pint --test
cd web && npm run lint && npm run build
```

API tests use an in-memory SQLite database by default. To run them against PostgreSQL, set `DB_CONNECTION=pgsql` and the other `DB_*` variables in the environment, as CI does.

## Environment variables

### `api/.env`

| Variable | Purpose |
|---|---|
| `APP_KEY`, `APP_URL`, `APP_ENV`, `APP_DEBUG` | Standard Laravel settings. Keep `APP_DEBUG=false` anywhere public |
| `DB_*` | Database connection. `DB_CONNECTION=pgsql` in production; `DB_SEARCH_PATH` is the schema (default `public`) and `DB_SSLMODE` is `require` for hosted databases |
| `REDIS_URL` | Full Redis connection URL (`rediss://` for TLS). Takes precedence over the host settings below |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_SCHEME` | Redis connection when `REDIS_URL` is empty. `REDIS_SCHEME` is `tcp` or `tls` |
| `REDIS_CLIENT` | `predis` (default) |
| `QUEUE_CONNECTION` | `redis` |
| `BROADCAST_CONNECTION` | `reverb` locally, `pusher` in production |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Local Reverb WebSocket server |
| `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` | Pusher Channels app, when `BROADCAST_CONNECTION=pusher` |
| `FRONTEND_URL` | Origin(s) of the web app, comma-separated. Used for CORS |
| `SANCTUM_EXPIRATION` | API token lifetime in minutes (default 7 days) |
| `JWT_SECRET` | Reserved for signed tokens (not used yet) |
| `CRON_SECRET` | Reserved for an externally triggered scheduler endpoint (not used yet) |
| `DEMO_LOGIN_ENABLED` | Reserved for a demo sign-in (not used yet) |

### `web/.env.local`

| Variable | Purpose |
|---|---|
| `VITE_API_URL` | Base URL of the API, including `/api` |
| `VITE_BROADCAST_DRIVER` | `reverb` locally, `pusher` in production |
| `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | Local Reverb server |
| `VITE_PUSHER_KEY`, `VITE_PUSHER_CLUSTER` | Pusher Channels app key and cluster (the key is public) |

## Deploying the API

`api/Dockerfile` builds a production image (FrankenPHP, PHP 8.4, OPcache, `pdo_pgsql`, `pcntl`) that listens on `$PORT` (default 8080). It sets `APP_ENV=production`, `APP_DEBUG=false` and logs to stderr; it contains no secrets, so set `APP_KEY`, the `DB_*` and Redis variables, the `PUSHER_*` variables (with `BROADCAST_CONNECTION=pusher`) and `FRONTEND_URL` in the host's environment. On every start, `api/docker/start.sh` caches config, routes and views, runs `php artisan migrate --force`, starts a Redis queue worker in the background (restarted if it exits) and then the web server. Horizon and Reverb are not run in production.

The intended host is a Render Docker web service (root directory `api`, health check `GET /api/health`) at `https://bugwatcher-pings-api.onrender.com`, with a hosted PostgreSQL database and a hosted Redis.

## Deploying the web app

The web app is intended for Vercel (project root `web`, Vite preset) with `VITE_API_URL` pointing at the API. `web/vercel.json` sets a Content-Security-Policy whose `connect-src` allows `'self'`, the API origin (`https://bugwatcher-pings-api.onrender.com`) and Pusher's WebSocket and HTTP endpoints. If the API moves, update that origin, or the app cannot reach the API.

## Security

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## License

Free for personal, educational and other noncommercial use under the [PolyForm Noncommercial License 1.0.0](LICENSE).

## Commercial use

Using BugWatcher Pings for commercial purposes requires a paid commercial license. Contact fazalabbas2002@gmail.com.
