<?php

namespace App\Contracts;

interface TcpProbe
{
    /** @return array{ok: bool, latency_ms: int|null, error: string|null} */
    public function check(string $host, int $port, float $timeout): array;
}
