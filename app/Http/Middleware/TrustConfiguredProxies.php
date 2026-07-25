<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Trust the reverse proxies named in config, resolved per request.
 *
 * Laravel's own helper takes the proxy list at bootstrap, which forced an
 * env() call outside a config file — and that returns null once the config is
 * cached, so the documented TRUSTED_PROXIES override never applied on any
 * install that ran `config:cache`. Reading config() here fixes that.
 *
 * X-Forwarded-Host stays OUT of the trusted set: proxies pass the original
 * Host through anyway, and honouring XFH would let a forged header poison
 * generated absolute URLs.
 *
 * Reported and diagnosed by @AJV20 in #7.
 */
class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies(): array|string|null
    {
        $proxies = config('trustedproxy.proxies');

        // A single '*' means "trust the immediate peer whatever it is"; Laravel
        // wants that as a bare string, not a one-element array.
        return $proxies === ['*'] ? '*' : $proxies;
    }

    protected function headers(): int
    {
        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX;
    }
}
