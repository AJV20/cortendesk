<?php

use App\Contracts\TcpProbe;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('settings', function ($table) {
        $table->string('key')->primary();
        $table->text('value')->nullable();
        $table->timestamps();
    });

    config()->set('cortendesk.id_server', 'id.example.test:21116');
    config()->set('cortendesk.relay_server', 'relay.example.test:21117');
});

test('liveness is public and proves the application can serve a request', function () {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['live' => true]);
});

test('readiness returns only dependency booleans when configured dependencies are available', function () {
    $this->app->instance(TcpProbe::class, new class implements TcpProbe
    {
        public function check(string $host, int $port, float $timeout): array
        {
            return ['ok' => true, 'latency_ms' => 1, 'error' => null];
        }
    });

    $this->getJson('/health/ready')
        ->assertOk()
        ->assertExactJson([
            'ready' => true,
            'database' => true,
            'id_server' => true,
            'relay_server' => true,
        ]);
});

test('readiness returns 503 with only dependency booleans when a configured service is unavailable', function () {
    $this->app->instance(TcpProbe::class, new class implements TcpProbe
    {
        public function check(string $host, int $port, float $timeout): array
        {
            return ['ok' => $host !== 'relay.example.test', 'latency_ms' => 1, 'error' => 'Connection refused'];
        }
    });

    $this->getJson('/health/ready')
        ->assertServiceUnavailable()
        ->assertExactJson([
            'ready' => false,
            'database' => true,
            'id_server' => true,
            'relay_server' => false,
        ]);
});
