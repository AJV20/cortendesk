<?php

use App\Support\UpdateChecker;
use Illuminate\Support\Facades\Cache;

afterEach(fn () => Cache::forget('cortendesk.latest_version'));

it('reports an upgrade when GitHub has a newer version', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.1']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    expect(UpdateChecker::upgradeAvailable())->toBe('0.8.0-beta.2');
});

it('reports no upgrade when versions match', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.2']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    expect(UpdateChecker::upgradeAvailable())->toBeNull();
});

it('reports no upgrade when running ahead of GitHub', function () {
    config(['cortendesk.api_version' => '0.9.0']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    expect(UpdateChecker::upgradeAvailable())->toBeNull();
});

it('reports no upgrade when the check has no data', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.1']);
    // nothing cached, and testing env never hits the network
    expect(UpdateChecker::upgradeAvailable())->toBeNull();
});

it('shows the upgrade badge in the topbar for admins only', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.1']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    $this->actingAs(\App\Models\User::factory()->admin()->create())
        ->get('/')->assertSee('Upgrade Available');

    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());
    $this->actingAs(\App\Models\User::factory()->create())
        ->get('/')->assertDontSee('Upgrade Available');
});
