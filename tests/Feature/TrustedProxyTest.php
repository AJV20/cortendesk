<?php

use App\Models\User;

it('generates https asset urls when behind a TLS-terminating proxy', function () {
    // Simulate the proxy hop: the container is reached over http, but the
    // original request was https and the proxy says so via X-Forwarded-Proto.
    $this->actingAs(User::factory()->admin()->create())
        ->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->get('/')
        ->assertOk()
        // Assets must be referenced over https, not the plain-http proxy hop.
        ->assertSee('https://localhost/assets', false)
        ->assertDontSee('http://localhost/assets', false);
});

it('ignores forwarded headers from untrusted (public) peers', function () {
    // A direct client from a public address must not be able to spoof scheme
    // or client IP — its REMOTE_ADDR is outside the trusted proxy ranges.
    $this->actingAs(User::factory()->admin()->create())
        ->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ])
        ->get('/')
        ->assertOk()
        ->assertDontSee('https://localhost/assets', false);

    expect(request()->ip())->toBe('203.0.113.50');
});

it('ignores a forged X-Forwarded-Host even from a trusted peer', function () {
    // XFH is excluded from the trusted header set: generated URLs must keep
    // the real Host, killing host-poisoning/open-redirect vectors.
    $this->actingAs(User::factory()->admin()->create())
        ->withServerVariables([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
        ])
        ->get('/')
        ->assertOk()
        ->assertDontSee('evil.example.com', false)
        ->assertSee('https://localhost/assets', false);
});

it('treats a forwarded-https request as secure', function () {
    // With the proxy trusted, X-Forwarded-Proto: https must mark the request
    // secure even though the container connection itself is plain http.
    $this->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->get('/login')
        ->assertOk();

    expect(request()->isSecure())->toBeTrue();
});
