<?php

namespace App\Contracts;

interface HealthProbeLimiter
{
    /**
     * Returns true when the request is within its endpoint's limit.
     */
    public function allows(string $endpoint, string $identity, int $maximumAttempts): bool;
}
