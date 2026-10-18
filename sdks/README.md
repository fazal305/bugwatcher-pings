# BugWatcher Pings SDKs

Two small clients that send errors to `POST /api/v1/ping`. Both are single files with no dependencies, never throw, and leave masking of secrets to the server (see the main README for the list of masked keys).

## JavaScript (browser)

`js/bugwatcher.js` is an ES module.

```js
import { init, captureException, setUser } from './bugwatcher.js';

init({
  key: 'bwp_pk_...',                                   // the project's public key
  endpoint: 'https://bugwatcher-pings-api.onrender.com/api/v1/ping',
  environment: 'production',                           // production, staging or development
  release: '1.4.0',                                    // optional
});

setUser({ id: 42 });                                   // optional; only the id is sent

try {
  riskyThing();
} catch (error) {
  captureException(error, { level: 'warning' });       // fatal, error (default), warning or info
}
```

After `init`, uncaught errors (`window.onerror`) and unhandled promise rejections are reported automatically. Stack traces from Chrome, Edge, Firefox and Safari are parsed into frames. Identical errors within one second are sent once. Reports go out with `fetch` and `keepalive`, so they survive a page unload; if `fetch` fails, the SDK falls back to `navigator.sendBeacon`, with the key in the body because a beacon can't set headers.

Example: serve the `js` folder (for example `python -m http.server 5591` inside `sdks/js`) and open `http://127.0.0.1:5591/example.html?key=bwp_pk_...&endpoint=http://127.0.0.1:8473/api/v1/ping`.

## PHP

`php/BugWatcher.php` is one framework-free class for PHP 8.1+ with the `curl` extension.

```php
require 'BugWatcher.php';

$bugwatcher = (new BugWatcher(
    key: 'bwp_pk_...',
    endpoint: 'https://bugwatcher-pings-api.onrender.com/api/v1/ping',
    environment: 'production',
    release: '1.4.0',
))->register();          // uncaught exceptions and fatal errors

$bugwatcher->setUser(42);

try {
    chargeCard($order);
} catch (Throwable $e) {
    $bugwatcher->captureException($e);   // returns the event id, or null if it couldn't be sent
}
```

`register()` installs an exception handler (it calls any handler that was already set, otherwise it logs the error and exits with status 255, as PHP would) and a shutdown function that reports fatal errors. Each frame carries up to 10 source lines before and after the failing line, read from the file on disk. Requests time out after one second. In a web request the method, URL, headers and query string are sent; the POST body is only sent when you pass `sendRequestBody: true`.

Example: `BUGWATCHER_KEY=bwp_pk_... php sdks/php/example.php` (set `BUGWATCHER_ENDPOINT` if the API isn't at `http://127.0.0.1:8473`).

### Laravel

Laravel already catches exceptions, so skip `register()` and report from the exception handler in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (Throwable $e) {
        app(BugWatcher::class)->captureException($e);
    });
})
```

and bind the client once, for example in `AppServiceProvider::register()`:

```php
$this->app->singleton(BugWatcher::class, fn () => new BugWatcher(
    key: config('services.bugwatcher.key'),
    endpoint: config('services.bugwatcher.endpoint'),
    environment: app()->environment('production') ? 'production' : 'staging',
));
```

Laravel's own reporting still runs, so errors keep reaching your logs.
