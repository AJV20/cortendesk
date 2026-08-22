<?php

namespace App\Services;

use App\Contracts\HealthProbeLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Throwable;

class FileHealthProbeLimiter implements HealthProbeLimiter
{
    public function __construct(private CacheFactory $cache) {}

    public function allows(string $endpoint, string $identity, int $maximumAttempts): bool
    {
        try {
            // This deliberately names Laravel's local persistent file store rather
            // than using the configured default, which may be database-backed.
            $store = $this->cache->store('file');
            $key = 'health-probe:'.hash('sha256', $endpoint.'|'.$identity);
            $result = $store->lock($key.':lock', 1)->get(function () use ($store, $key, $maximumAttempts): array {
                $attempts = (int) $store->get($key, 0) + 1;
                // Use Repository::put so its DateTime TTL conversion preserves
                // the one-minute expiry on Laravel's file store.
                $store->put($key, $attempts, now()->addMinute());

                return ['allowed' => $attempts <= max(1, $maximumAttempts)];
            });

            // A contended/unavailable local lock is not grounds for turning an
            // outage report into a false negative.
            return is_array($result) ? $result['allowed'] : true;
        } catch (Throwable) {
            // A probe must still report the outage it observes if its limiter's
            // filesystem/cache backend is itself unavailable.
            return true;
        }
    }
}
