<?php

namespace App\Services;

use App\Contracts\TcpProbe;

class SocketTcpProbe implements TcpProbe
{
    public function check(string $host, int $port, float $timeout): array
    {
        $started = microtime(true);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout);
        $latency = (int) round((microtime(true) - $started) * 1000);

        if (is_resource($socket)) {
            fclose($socket);

            return ['ok' => true, 'latency_ms' => $latency, 'error' => null];
        }

        return ['ok' => false, 'latency_ms' => $latency, 'error' => $errno ? 'Connection failed ('.$errno.').' : 'Connection failed.'];
    }
}
