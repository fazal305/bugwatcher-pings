<?php

$frontend = array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:5473')))));

return [

    // Listed first by the queue workers (see docker/start.sh), so alerts never wait behind a backlog of pings.
    'queue' => 'webhooks',

    // Base of the "View in BugWatcher" links: the first FRONTEND_URL origin.
    'frontend_url' => rtrim($frontend[0] ?? 'http://localhost:5473', '/'),

    // Local development only: lets generic webhooks reach private addresses, plain http and other ports, so a
    // receiver on this machine can be tested. Ignored when APP_ENV=production.
    'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),

    'connect_timeout' => 2,
    'timeout' => 5,
    'tries' => 3,
    // Milliseconds to wait before the second and third try.
    'retry_delays' => [250, 1000],

    // Seconds a project's endpoint list stays cached; every change to an endpoint clears it at once.
    'endpoint_cache_ttl' => 300,

    'cooldown_key_prefix' => 'bugwatcher:webhook-cooldown:',

    'test_rate_limit_per_minute' => 5,
    'write_rate_limit_per_minute' => 20,

];
