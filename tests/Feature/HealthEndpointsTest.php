<?php

use App\Contracts\HealthProbeLimiter;
use App\Contracts\TcpProbe;
use App\Models\Setting;
use App\Services\FileHealthProbeLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Cache::store('file')->flush();
    Schema::dropIfExists('settings');

    Schema::create('settings', function ($table) {
        $table->string('key')->primary();
        $table->text('value')->nullable();
        $table->timestamps();
    });

    config()->set('cortendesk.id_server', 'id.example.test:21116');
    config()->set('cortendesk.relay_server', 'relay.example.test:21117');
});

test('health routes attach the dedicated probe limiter middleware', function () {
    $routes = collect($this->app['router']->getRoutes()->getRoutes())->keyBy(fn ($route) => $route->uri());

    expect($routes['health/live']->gatherMiddleware())->toContain('health-probe:live');
    expect($routes['health/ready']->gatherMiddleware())->toContain('health-probe:ready');
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

test('liveness remains process-only when the health probe limiter backend fails', function () {
    $this->app->instance(HealthProbeLimiter::class, new class implements HealthProbeLimiter
    {
        public function allows(string $endpoint, string $identity, int $maximumAttempts): bool
        {
            throw new RuntimeException('file cache is unavailable');
        }
    });

    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['live' => true]);
});

test('readiness returns 503 dependency booleans when the database and database cache are unavailable', function () {
    config()->set('database.default', 'health_unavailable');
    config()->set('database.connections.health_unavailable', [
        'driver' => 'sqlite',
        'database' => '/tmp/cortendesk-health-unavailable/database.sqlite',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.connection', 'health_unavailable');
    config()->set('cache.stores.database.table', 'unavailable_cache');
    app('db')->purge('health_unavailable');
    app('cache')->forgetDriver('database');

    $this->getJson('/health/ready')
        ->assertServiceUnavailable()
        ->assertExactJson([
            'ready' => false,
            'database' => false,
            'id_server' => false,
            'relay_server' => false,
        ]);
});

test('liveness is rate limited with a booleans-only liveness response', function () {
    config()->set('health.probe_limits.live', 2);

    $this->getJson('/health/live')->assertOk();
    $this->getJson('/health/live')->assertOk();
    $this->getJson('/health/live')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')
        ->assertExactJson(['live' => false]);
});

test('liveness uses one endpoint bucket despite forwarded client rotation from a private proxy', function () {
    config()->set('health.probe_limits.live', 2);

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.10',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
    ])->getJson('/health/live')->assertOk();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.10',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.2',
    ])->getJson('/health/live')->assertOk();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.10',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.3',
    ])->getJson('/health/live')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')
        ->assertExactJson(['live' => false]);
});

test('a contended limiter lock rejects rather than freely allowing the request', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()
        ->with(1, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException);

    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('lock')->once()->andReturn($lock);

    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldReceive('store')->once()->with('file')->andReturn($store);

    expect((new FileHealthProbeLimiter($cache))->allows('live', 'global', 1))->toBeFalse();
});

test('readiness has its own rate limit with a booleans-only readiness response', function () {
    config()->set('health.probe_limits.ready', 1);
    $this->app->instance(TcpProbe::class, new class implements TcpProbe
    {
        public function check(string $host, int $port, float $timeout): array
        {
            return ['ok' => true, 'latency_ms' => 1, 'error' => null];
        }
    });

    $this->getJson('/health/ready')->assertOk();
    $this->getJson('/health/ready')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')
        ->assertExactJson([
            'ready' => false,
            'database' => false,
            'id_server' => false,
            'relay_server' => false,
        ]);
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
