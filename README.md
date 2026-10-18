# BugWatcher Pings

BugWatcher Pings is a real-time error monitoring and alerting app. Your applications send error reports ("pings") to a Laravel API, which queues them on Redis. Workers parse each report, group repeats of the same error into one issue and store it, then broadcast it over WebSockets to a React dashboard with a live feed and send an alert to Slack or Discord.

**Live demo:** not deployed yet.

## Status

Working so far:

- Accounts: sign up (16 or older, confirmed with a checkbox; no date of birth is stored), sign in, sign out and a shared demo account, using Sanctum bearer tokens that expire after `SANCTUM_EXPIRATION` minutes
- Projects with a public SDK key (`bwp_pk_…`) and members who are either an admin or a developer
- Laravel API with JSON error responses (no stack traces when `APP_DEBUG=false`) and CORS limited to the web app's origin
- Configuration for PostgreSQL, a Redis queue (through Predis), and broadcasting through Laravel Reverb locally or Pusher in production
- Production Docker image for the API that runs migrations, switches on row level security, and runs a Redis queue worker and the web server
- React dashboard: landing page, sign in and sign up, projects list, project settings and an empty live feed

Planned: error ingestion, fingerprinting and grouping, the live feed over WebSockets, and Slack and Discord alerts.

## Accounts, projects and roles

| Endpoint | Who | What |
|---|---|---|
| `POST /api/auth/register` | anyone | `name`, `email`, `password` (8+ characters), `age_confirmed` (must be accepted) |
| `POST /api/auth/login` | anyone | Wrong email and wrong password get the same reply |
| `POST /api/auth/demo` | anyone, when `DEMO_LOGIN_ENABLED=true` | Signs into the seeded demo account; 404 otherwise |
| `GET /api/me`, `POST /api/auth/logout` | signed in | |
| `GET /api/projects`, `POST /api/projects` | signed in | Lists the projects you belong to; creating one makes you its admin |
| `GET /api/projects/{slug}` | members | Includes the public key, which both roles need to set up the SDK |
| `PATCH /api/projects/{slug}` | admins | Rename (the slug stays the same) |
| `POST /api/projects/{slug}/rotate-key` | admins | Issues a new public key; the old one stops working |
| `GET /api/projects/{slug}/members` | members | Only admins see members' email addresses |
| `POST /api/projects/{slug}/members` | admins | `email`, `role`. Always answers 202 with the same message, whether or not the account exists |
| `PATCH`, `DELETE /api/projects/{slug}/members/{id}` | admins | Change a role or remove a member. A project always keeps at least one admin |

Authorization is handled by `ProjectPolicy`, run as route middleware before validation. Someone who isn't a member gets the same `404 Not Found.` as for a project that doesn't exist, so slugs can't be probed; a developer trying an admin action gets `403`. Member ids are scoped to the project in the URL.

Sign-in, sign-up and the demo are rate limited per IP address and (for sign-in and sign-up) per email address; over the limit the API answers `429` with a short message that the web app shows.

Run `php artisan db:seed` to create the demo account ("Demo") and its project, "Demo Storefront (sample data)". The seeder is safe to run again. The demo account is shared by everyone who uses it, and the app says so while you're in it.

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
php artisan db:seed            # demo account and project
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
| `DEMO_LOGIN_ENABLED` | `true` turns on `POST /api/auth/demo` (default `false`). Needs the demo account from `php artisan db:seed` |

### `web/.env.local`

| Variable | Purpose |
|---|---|
| `VITE_API_URL` | Base URL of the API, including `/api` |
| `VITE_BROADCAST_DRIVER` | `reverb` locally, `pusher` in production |
| `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | Local Reverb server |
| `VITE_PUSHER_KEY`, `VITE_PUSHER_CLUSTER` | Pusher Channels app key and cluster (the key is public) |

## Deploying the API

`api/Dockerfile` builds a production image (FrankenPHP, PHP 8.4, OPcache, `pdo_pgsql`, `pcntl`) that listens on `$PORT` (default 8080). It sets `APP_ENV=production`, `APP_DEBUG=false` and logs to stderr; it contains no secrets, so set `APP_KEY`, the `DB_*` and Redis variables, the `PUSHER_*` variables (with `BROADCAST_CONNECTION=pusher`) and `FRONTEND_URL` in the host's environment. On every start, `api/docker/start.sh` caches config, routes and views, runs `php artisan migrate --force`, switches on row level security (`php artisan db:enable-rls`, see below), starts a Redis queue worker in the background (restarted if it exits) and then the web server. Horizon and Reverb are not run in production.

The intended host is a Render Docker web service (root directory `api`, health check `GET /api/health`) at `https://bugwatcher-pings-api.onrender.com`, with a hosted PostgreSQL database and a hosted Redis.

### Row level security

The API reaches PostgreSQL through its own role, which owns the app's schema and tables. `php artisan db:enable-rls` (a no-op on other databases) switches on row level security for every table in the schema and adds one explicit policy to each, `deny_non_owner_roles`: a restrictive policy for all commands and all roles with `USING (false) WITH CHECK (false)`. RLS is not forced, so the owning app role is unaffected, while any other role, such as a hosted provider's API roles, is denied every row even if it is later granted access to a table. Because the policy is restrictive, the denial still holds if a permissive policy is added later. The command is safe to run repeatedly and runs after every migration in `start.sh`, so new tables are covered.

For PostgreSQL in a dedicated schema of a shared database (for example a Supabase project), give the app its own login role that owns the schema, with no access granted to other roles, and set `DB_SEARCH_PATH` to that schema.

## Deploying the web app

The web app is intended for Vercel (project root `web`, Vite preset) with `VITE_API_URL` pointing at the API. `web/vercel.json` sets a Content-Security-Policy whose `connect-src` allows `'self'`, the API origin (`https://bugwatcher-pings-api.onrender.com`) and Pusher's WebSocket and HTTP endpoints. If the API moves, update that origin, or the app cannot reach the API.

## Security

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## License

Free for personal, educational and other noncommercial use under the [PolyForm Noncommercial License 1.0.0](LICENSE).

## Commercial use

Using BugWatcher Pings for commercial purposes requires a paid commercial license. Contact fazalabbas2002@gmail.com.
