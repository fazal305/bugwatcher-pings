<?php

return [

    // Cache store for project key lookups and ingest rate limits. Must be shared by every API instance.
    'cache_store' => env('PINGS_CACHE_STORE', 'redis'),

    'key_cache_ttl' => (int) env('PINGS_KEY_CACHE_TTL', 60),
    'key_cache_negative_ttl' => (int) env('PINGS_KEY_CACHE_NEGATIVE_TTL', 30),

    // Pings per second allowed for one project key and for one client IP address.
    'rate_limit_per_key' => (int) env('PINGS_RATE_LIMIT_PER_KEY', 300),
    'rate_limit_per_ip' => (int) env('PINGS_RATE_LIMIT_PER_IP', 300),

    'max_body_bytes' => 65536,
    'max_frames' => 50,
    'max_context_lines' => 10,

    'queue' => env('PINGS_QUEUE', 'ingest'),

    // Redis list that holds masked payloads while the database is unreachable.
    'redis_connection' => env('PINGS_REDIS_CONNECTION', 'default'),
    'spool_key' => 'bugwatcher:spool',
    'dead_letter_key' => 'bugwatcher:spool:dead',
    'max_spool_cycles' => (int) env('PINGS_MAX_SPOOL_CYCLES', 10),

    'retention_days' => (int) env('PINGS_RETENTION_DAYS', 30),

    'sensitive_keys' => [
        'password', 'bearer_token', 'credit_card', 'authorization', 'cookie', 'set-cookie', 'token', 'access_token',
        'refresh_token', 'api_key', 'apikey', 'secret', 'client_secret', 'card_number', 'cvv', 'ssn', 'x-api-key',
    ],

];
