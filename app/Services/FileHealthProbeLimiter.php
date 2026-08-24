<?php

namespace App\Services;

use App\Contracts\HealthProbeLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
            // File-store locks serialize the read/modify/write sequence. Wait at
            // most one second: that covers the tiny critical section without
            // making a health probe a long blocking request.
            $result = $store->lock($key.':lock', 1)->block(1, function () use ($store, $key, $maximumAttempts): array {
                $attempts = (int) $store->get($key, 0) + 1;
                // Use Repository::put so its DateTime TTL conversion preserves
                // the one-minute expiry on Laravel's file store.
                $store->put($key, $attempts, now()->addMinute());

                return ['allowed' => $attempts <= max(1, $maximumAttempts)];
            });

            return $result['allowed'];
        } catch (LockTimeoutException) {
            // Contention is not a limiter/backend failure. Reject rather than
            // letting a contested request bypass the protective limit.
            return false;
        } catch (Throwable) {
            // A probe must still report the outage it observes if its limiter's
            // filesystem/cache backend is itself unavailable.
            return true;
        }
    }
}
