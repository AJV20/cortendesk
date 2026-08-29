<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    | Which peers may set X-Forwarded-* on the way in. Read here, in a config
    | file, rather than via env() at bootstrap: `php artisan config:cache` (run
    | by the Docker image on every start) stops loading .env
    | entirely, so an env() call outside a config file silently returns null and
    | a TRUSTED_PROXIES set in .env was quietly ignored. Config files are
    | evaluated while the cache is BUILT, so the value survives.
    |
    | Deliberately not '*': only loopback proxies are trusted by default. A
    | reverse proxy on another address must be listed explicitly. This prevents
    | a client reaching an exposed app port from forging X-Forwarded-Proto to
    | make plaintext HTTP look secure. Set TRUSTED_PROXIES to the exact proxy
    | address or narrow CIDR; never use a broad network merely for convenience.
    */

    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'TRUSTED_PROXIES',
        '127.0.0.1,::1',
    ))))),

];
