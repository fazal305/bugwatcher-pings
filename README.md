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
- Error ingestion: `POST /api/v1/ping` masks secrets and queues each report on Redis without touching the database, background workers group reports into issues by fingerprint, and reports are kept in Redis while the database is down
- JavaScript (browser) and PHP SDKs in [`sdks/`](sdks/README.md)

Planned: the live feed over WebSockets, and Slack and Discord alerts.

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

## Error ingestion

### `POST /api/v1/ping`

No sign-in; the project's public key identifies it, in the `X-BugWatcher-Key` header or, for `navigator.sendBeacon` (which can't set headers), as `key` in the body. The body is JSON (sent as `application/json` or `text/plain`), at most 64 KB.

```bash
curl -i -X POST http://127.0.0.1:8473/api/v1/ping \
  -H "Content-Type: application/json" \
  -H "X-BugWatcher-Key: bwp_pk_..." \
  -d '{
    "message": "Cart total is undefined",
    "level": "error",
    "environment": "production",
    "timestamp": 1760000000000,
    "release": "1.4.0",
    "exception": {
      "type": "TypeError",
      "frames": [
        {"file": "https://shop.example/assets/index-3fa9c1d2.js", "line": 42, "function": "Cart.total",
         "context_line": "return cart.total.toFixed(2);", "pre_context": ["function total(cart) {"], "post_context": ["}"]}
      ]
    },
    "request": {"method": "GET", "url": "https://shop.example/cart?token=abc", "headers": {"Authorization": "Bearer x"}},
    "user": {"id": "42"},
    "sdk": {"name": "my-sdk", "version": "1.0.0"}
  }'
```

| Field | Rules |
|---|---|
| `message` | Required string, up to 2,000 characters |
| `level` | Required: `fatal`, `error`, `warning` or `info` |
| `environment` | Required: `production`, `staging` or `development` |
| `timestamp` | Required: milliseconds since the Unix epoch, or an ISO 8601 date. Stored as the event's `occurred_at` |
| `exception.type` | Optional string |
| `exception.frames[]` | Optional, innermost frame first: `file`, `line`, `function`, `in_app`, `context_line`, `pre_context[]`, `post_context[]`. Only the first 50 frames and the 10 lines on each side of the failing line are kept |
| `request` | Optional: `method`, `url`, `headers` (object), `query` (object or string), `body` (any JSON) |
| `user.id`, `release`, `sdk.name`, `sdk.version` | Optional strings |

Other fields are dropped. Replies:

| Status | When |
|---|---|
| `202` `{"id": "<ulid>"}` | Queued. The id becomes the stored event's id |
| `401` `{"message": "Unauthorized."}` | Missing, malformed or unknown key (the same reply for all three) |
| `413` | Body over 64 KB |
| `422` `{"message", "errors": {field: [...]}}` | Not a JSON object, or a field fails the rules above |
| `429` with `Retry-After: 1` | Over `PINGS_RATE_LIMIT_PER_KEY` pings a second for the key, or `PINGS_RATE_LIMIT_PER_IP` for the client IP (default 300 each) |
| `503` with `Retry-After: 30` | Only for a key that isn't cached while the database is down |

The route answers CORS requests from any origin, without credentials, so the SDKs work from any site; the rest of the API only allows `FRONTEND_URL`. Every reply carries a `Server-Timing` header: `ingest` is the time spent in the controller and `total` the time since PHP received the request.

The route uses no session, auth guard or database: the key is looked up through the Redis cache (60 seconds for a known key, 30 for an unknown one, so a flood of bad keys can't reach the database either; rotating a key clears its entry at once), the rate limits are two Redis counters bumped in one round trip, and the masked report is pushed onto the `ingest` Redis queue as a `ProcessPing` job.

### SDKs

[`sdks/`](sdks/README.md) has two dependency-free clients, each with a runnable example:

- `sdks/js/bugwatcher.js`, an ES module for browsers: `init({ key, endpoint, environment, release })` reports uncaught errors and unhandled rejections, parses Chrome and Firefox stack traces, sends with `fetch` keepalive (falling back to `sendBeacon`), drops repeats of the same error within a second, and offers `captureException(error)`.
- `sdks/php/BugWatcher.php`, one class for any PHP app: `register()` reports uncaught exceptions and fatal errors with source context, and `captureException($e)` reports handled ones. It leaves the POST body out unless asked, times out after one second and never throws. The SDK README shows how to use it from Laravel.

### Masking

Before anything reaches Redis, the API replaces with `[Filtered]` the value of every key, at any depth, whose name contains `password`, `token` (which covers `bearer_token`, `access_token` and `refresh_token`), `secret` (covers `client_secret`), `authorization`, `cookie` (covers `set-cookie`), `api_key`, `apikey`, `x-api-key`, `credit_card`, `card_number`, `cvv` or `ssn`. Matching ignores case and separators, so `user_password`, `X-Api-Key`, `accessToken` and `Credit-Card` all match; `cvv` and `ssn` must be a whole word, so `className` is left alone. The same check is applied to the parameters in `request.url`'s query string and to a `request.query` string. The list is in `api/config/pings.php`.

### Processing, grouping and regressions

Queue workers (`php artisan queue:work redis --queue=ingest,default`) store each report as a row in `events` and fold it into an `issues` row in one transaction. Reports with the same fingerprint share an issue. The fingerprint is a SHA-256 of the project, the exception type and the top in-app frame's file and function, without line numbers and with build hashes such as `index-3fa9c1d2.js` removed, so an error keeps its issue across deploys and when code above it moves. Without a usable frame the message is used instead, with numbers, hex values and UUIDs replaced. The issue's `event_count` and `last_seen_at` are updated with one atomic `UPDATE`; when a `resolved` issue gets a new event it is reopened and that event is flagged `is_regression`. A report delivered twice (same id) is stored once. After each commit the worker fires `App\Events\PingProcessed` with the event, the issue and the `isNewIssue` and `isRegression` flags; nothing listens to it yet.

| Table | Columns |
|---|---|
| `issues` | `project_id`, `fingerprint`, `title`, `culprit` (file:line or function), `level`, `environment`, `status` (`open` or `resolved`), `first_seen_at`, `last_seen_at`, `event_count`. Unique per project and fingerprint |
| `events` | ULID `id`, `project_id`, `issue_id`, `level`, `environment`, `message`, `exception_type`, `file`, `line`, `stacktrace`, `request`, `user_context` (JSON), `release`, `sdk`, `is_regression`, `occurred_at`, `received_at`, `processed_at` (millisecond precision) |

### When the database is down

- A worker that can't reach the database pushes the (already masked) report onto the Redis list `bugwatcher:spool` instead of failing it. A job that fails for any other reason after 3 tries (2 and 10 seconds apart) also goes to the spool.
- `php artisan pings:drain-spool` moves spooled reports back onto the queue once the database answers; the scheduler runs it every minute and the cron endpoint runs it too. A report that has been through the spool `PINGS_MAX_SPOOL_CYCLES` times (default 10) is moved to `bugwatcher:spool:dead` for inspection instead of looping forever.
- The ingest route keeps answering 202 for keys it has seen recently: a cached key is reused for up to a day when the database can't be asked. Only a key that isn't cached at all gets 503.
- Use Redis as the cache store (`CACHE_STORE=redis`): workers check the cache between jobs, so a database cache would stop them during an outage. Redis should keep its data (AOF or RDB persistence on the host), since the queue and the spool live there until the database is back.

### Retention and the cron endpoint

`php artisan pings:prune` deletes events older than `PINGS_RETENTION_DAYS` (default 30) in batches of 5,000; it runs daily. Issues are kept.

`POST /api/cron/run` runs `pings:drain-spool` and `pings:prune` for hosts without cron. It needs `Authorization: Bearer <CRON_SECRET>` (compared in constant time), answers 401 otherwise or when `CRON_SECRET` is empty, and is limited to 10 calls a minute per IP.

### Measured throughput and latency

Measured on 18 October 2026 on a laptop (Intel Core i5-10210U, 4 cores and 8 threads, 8 GB RAM, Windows 11) running Docker Desktop on WSL 2. The API ran as the production image (`api/Dockerfile`: Octane on FrankenPHP, 16 PHP threads) against the `docker-compose.yml` PostgreSQL 17 and Redis 7, with the rate limits raised out of the way. Load came from `grafana/k6` (constant arrival rate, 20 seconds a step) in the same Docker network, so the load generator, the API, its queue workers, PostgreSQL and Redis all shared the same 8 virtual CPUs. Each ping was about 1 KB (two frames, request headers, a query string to mask) and all of them belonged to one issue, the worst case for the workers, since every event updates the same issue row. Latency is for 202 responses as measured by k6; `ingest` and `total` come from the `Server-Timing` header; end to end is `processed_at - occurred_at` over every event of the step (both clocks in the same Docker VM).

With 4 queue workers (`QUEUE_WORKERS=4`):

| Target rate | Achieved | Errors | Latency p50 / p95 / p99 | `ingest` p50 / p95 | `total` p50 / p95 | End to end p50 / p95 |
|---|---|---|---|---|---|---|
| 50/s | 50/s | 0 of 1,000 | 4.6 / 9.1 / 19.4 ms | 1.5 / 2.8 ms | 3.1 / 6.2 ms | 6 / 11 ms |
| 100/s | 100/s | 0 of 2,001 | 4.4 / 10.2 / 19.5 ms | 1.4 / 3.1 ms | 3.1 / 6.7 ms | 5 / 14 ms |
| 250/s | 250/s | 0 of 5,000 | 7.3 / 14.0 / 23.3 ms | 2.3 / 5.6 ms | 5.2 / 10.2 ms | 412 / 711 ms |
| 500/s | 500/s | 0 of 10,001 | 14.5 / 193 / 292 ms | 4.7 / 23.2 ms | 9.3 / 36.9 ms | 17.2 / 24.2 s |
| 1,000/s | 479/s | 0 of 11,547 (8,371 not sent) | 2.85 / 7.82 / 7.95 s | 9.2 / 47.2 ms | 16.1 / 68.0 ms | 40.1 / 50.7 s |
| 2,000/s | 385/s | 0 of 10,038 (29,977 not sent) | 6.43 / 6.96 / 7.06 s | 12.2 / 60.4 ms | 23.4 / 92.0 ms | not measured |
| 5,000/s | 286/s | 0 of 9,312 (61,158 not sent) | 4.55 / 18.2 / 18.4 s | 11.7 / 52.8 ms | 22.0 / 84.0 ms | not measured |

With no queue workers, so the API shared the CPUs only with k6, PostgreSQL and Redis: 756 pings/s achieved at a 1,000/s target and 784/s at 2,000/s, with no errors, `ingest` p50 7.7 ms and p95 25 ms.

What this shows:

- On this machine the ingest route sustains about 500 pings a second with workers running and about 780 without, with no errors at any rate. "Not sent" means k6 ran out of its 3,000 virtual users while waiting for replies: the server was saturated and queueing connections, not failing them. **The 5,000 pings/s design target was not reached here.** Reaching it needs more CPU than one laptop VM shared with the load generator; the ingest route keeps no state outside Redis, so it scales out by adding instances.
- Server time stays under 10 ms at the median up to 500/s, and rises above it once the CPUs are saturated.
- Four workers store about 175 to 220 events a second for a single hot issue (each event holds that issue's row lock for one update and the commit). Above that, reports wait on the Redis queue, which is what the end-to-end column shows. For every step up to 1,000/s, all accepted pings were stored once the queue drained; the 2,000/s and 5,000/s backlogs were discarded unprocessed to save time. More workers, or reports spread over more issues, raise this.
- Before the production image ran Octane (plain FrankenPHP, booting Laravel on every request) the same setup peaked at about 140 pings a second, with `ingest` p50 around 20 ms.

## Stack

| Part | Technology |
|---|---|
| `api/` | Laravel 13 (PHP 8.4), Laravel Sanctum, Laravel Octane on FrankenPHP (production image), PostgreSQL, Redis queue via Predis, Laravel Reverb (local) or Pusher (production) for broadcasting, Laravel Horizon for local queue monitoring |
| `web/` | React 19, Vite, Tailwind CSS 4, React Router — plain JavaScript/JSX |
| `sdks/` | Dependency-free JavaScript (browser) and PHP clients for the ingestion API |

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
php artisan queue:work redis --queue=ingest,default   # process pings and other jobs
php artisan schedule:work                             # drains the spool every minute, prunes daily
php artisan reverb:start                              # WebSocket server on port 8080
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

API tests use an in-memory SQLite database by default. To run them against PostgreSQL, set `DB_CONNECTION=pgsql` and the other `DB_*` variables in the environment, as CI does. The spool and Redis rate-limit tests need Redis on `127.0.0.1:6379` (`docker compose up -d`); they use their own key prefix.

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
| `CRON_SECRET` | Bearer secret for `POST /api/cron/run`; empty turns the endpoint off |
| `CACHE_STORE` | `redis`. Queue workers check the cache for restart signals, so a Redis cache keeps them running (and spooling) while the database is down |
| `PINGS_CACHE_STORE` | Cache store for project key lookups and ingest rate limits (default `redis`) |
| `PINGS_KEY_CACHE_TTL`, `PINGS_KEY_CACHE_NEGATIVE_TTL` | Seconds a known key (default 60) and an unknown key (default 30) stay cached |
| `PINGS_RATE_LIMIT_PER_KEY`, `PINGS_RATE_LIMIT_PER_IP` | Pings per second per project key and per client IP (default 300 each) |
| `PINGS_QUEUE` | Queue name for pings (default `ingest`) |
| `PINGS_RETENTION_DAYS` | Events older than this are deleted by `pings:prune` (default 30) |
| `PINGS_MAX_SPOOL_CYCLES` | How many times a ping that keeps failing goes back through the spool before it is set aside (default 10) |
| `QUEUE_WORKERS` | Docker image only: queue workers started next to the web server (default 1) |
| `DEMO_LOGIN_ENABLED` | `true` turns on `POST /api/auth/demo` (default `false`). Needs the demo account from `php artisan db:seed` |

### `web/.env.local`

| Variable | Purpose |
|---|---|
| `VITE_API_URL` | Base URL of the API, including `/api` |
| `VITE_BROADCAST_DRIVER` | `reverb` locally, `pusher` in production |
| `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | Local Reverb server |
| `VITE_PUSHER_KEY`, `VITE_PUSHER_CLUSTER` | Pusher Channels app key and cluster (the key is public) |

## Deploying the API

`api/Dockerfile` builds a production image (FrankenPHP, PHP 8.4, OPcache, `pdo_pgsql`, `pcntl`) that listens on `$PORT` (default 8080). It sets `APP_ENV=production`, `APP_DEBUG=false` and logs to stderr; it contains no secrets, so set `APP_KEY`, the `DB_*` and Redis variables, the `PUSHER_*` variables (with `BROADCAST_CONNECTION=pusher`) and `FRONTEND_URL` in the host's environment. On every start, `api/docker/start.sh` caches config, routes and views, runs `php artisan migrate --force`, switches on row level security (`php artisan db:enable-rls`, see below), starts `QUEUE_WORKERS` Redis queue workers and the scheduler in the background (each restarted if it exits) and then the web server: Laravel Octane on FrankenPHP in worker mode, which keeps the app booted between requests. Horizon and Reverb are not run in production.

Render's free tier has no cron and sleeps when idle, so `.github/workflows/cron.yml` calls `POST /api/cron/run` every 15 minutes. Set the repository variable `API_URL` and the secret `CRON_SECRET` (the same value as the API's `CRON_SECRET`) to switch it on.

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
