<?php

use App\Http\Middleware\ApiTokenCan;
use App\Http\Middleware\ConsoleCan;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireEmailAddress;
use App\Http\Middleware\RequireMailHealthy;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Models\TrustedDevice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'api-token-can' => ApiTokenCan::class,
            // Delegated console roles (PLAN D4). `admin` stays the super-admin
            // gate; `console-can` is the per-area one.
            'console-can' => ConsoleCan::class,
        ]);
        $middleware->appendToGroup('web', EnsureUserIsActive::class);
        // 2FA enrollment enforcement runs after the active-user check.
        $middleware->appendToGroup('web', RequireTwoFactor::class);
        $middleware->appendToGroup('web', RequireEmailAddress::class);
        $middleware->appendToGroup('web', RequireMailHealthy::class);

        // The trusted-device cookie (PLAN D1) is an opaque random id whose
        // sha256 is what the server actually checks, so encrypting it buys
        // nothing — and leaving it in the clear keeps the value stable for
        // anything that has to read it back verbatim.
        $middleware->encryptCookies(except: [TrustedDevice::COOKIE]);

        // Honor X-Forwarded-* from a TLS-terminating reverse proxy (Traefik,
        // Caddy, nginx-proxy-manager, Cloudflare, …). Without this Laravel sees
        // the plain-HTTP hop from the proxy and generates http:// asset URLs,
        // which browsers block as mixed content on an https page.
        //
        // The proxy list and the header policy live in TrustConfiguredProxies /
        // config/trustedproxy.php, NOT here: this closure runs outside a config
        // file, where env() returns null once `config:cache` has run — which
        // the Docker image does on every start. Configuring it
        // here meant TRUSTED_PROXIES in .env was silently ignored in production
        // (reported in #7).
        $middleware->replace(
            \Illuminate\Http\Middleware\TrustProxies::class,
            TrustConfiguredProxies::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
