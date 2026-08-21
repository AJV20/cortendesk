<?php

use App\Contracts\TcpProbe;
use App\Models\Setting;
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

test('liveness remains process-only when the default database cache is unavailable', function () {
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.table', 'unavailable_cache');
    app('cache')->forgetDriver('database');

    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['live' => true]);
});

test('readiness returns only dependency booleans when every configured relay in the operative pool is available', function () {
    Setting::put('relay_servers', json_encode([
        ['address' => 'relay-one.example.test:21117', 'geo' => 'one'],
        ['address' => 'relay-two.example.test:21117', 'geo' => 'two'],
    ]));
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

test('readiness requires every configured relay in the operative relay pool to be available', function () {
    Setting::put('relay_servers', json_encode([
        ['address' => 'relay-one.example.test:21117', 'geo' => 'one'],
        ['address' => 'relay-two.example.test:21117', 'geo' => 'two'],
    ]));
    $this->app->instance(TcpProbe::class, new class implements TcpProbe
    {
        public function check(string $host, int $port, float $timeout): array
        {
            return ['ok' => $host !== 'relay-two.example.test', 'latency_ms' => 1, 'error' => 'Connection refused'];
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

test('readiness is not blocked by an absent relay configuration', function () {
    config()->set('cortendesk.relay_server', '');
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
