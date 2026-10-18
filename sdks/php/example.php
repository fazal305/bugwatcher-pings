<?php

// Usage: BUGWATCHER_KEY=bwp_pk_... [BUGWATCHER_ENDPOINT=http://127.0.0.1:8473/api/v1/ping] php sdks/php/example.php

require __DIR__.'/BugWatcher.php';

$bugwatcher = (new BugWatcher(
    key: (string) getenv('BUGWATCHER_KEY'),
    endpoint: getenv('BUGWATCHER_ENDPOINT') ?: 'http://127.0.0.1:8473/api/v1/ping',
    environment: 'development',
    release: 'example-1.0.0',
))->register();
$bugwatcher->setUser(42);

function chargeCard(array $order): void
{
    if ($order['total'] <= 0) {
        throw new InvalidArgumentException("Order {$order['id']} has an invalid total");
    }
}

// A handled error. Outside a web request there is no request context, so the example passes one; the server masks
// the password, the Authorization header and the token in the URL before anything is stored.
try {
    chargeCard(['id' => 1001, 'total' => 0]);
} catch (InvalidArgumentException $e) {
    $id = $bugwatcher->captureException($e, 'error', [
        'method' => 'POST',
        'url' => 'https://shop.example/checkout?token=abc123&step=2',
        'headers' => ['Authorization' => 'Bearer example-secret', 'Accept' => 'application/json'],
        'body' => ['email' => 'customer@example.com', 'password' => 'hunter2'],
    ]);
    echo 'Handled error reported: '.($id ?? 'not sent').PHP_EOL;
}

// An uncaught error, reported by the registered exception handler.
chargeCard(['id' => 1002, 'total' => -5]);
