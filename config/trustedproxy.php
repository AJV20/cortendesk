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
    | Deliberately not '*': headers are honoured only when the immediate peer is
    | a private or loopback address (Docker bridges, same-box nginx). A client
    | hitting an exposed port directly from a public address therefore cannot
    | forge X-Forwarded-For into the audit logs. Set TRUSTED_PROXIES (comma
    | separated CIDRs, or '*') when your proxy reaches the app from a public
    | address — Cloudflare or an external load balancer.
    */

    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'TRUSTED_PROXIES',
        '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
    ))))),

];
