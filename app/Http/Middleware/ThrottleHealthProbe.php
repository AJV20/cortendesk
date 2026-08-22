<?php

namespace App\Http\Middleware;

use App\Contracts\HealthProbeLimiter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ThrottleHealthProbe
{
    public function handle(Request $request, Closure $next, string $endpoint): Response
    {
        try {
            $maximumAttempts = (int) config('health.probe_limits.'.$endpoint, 1);
            $allowed = app(HealthProbeLimiter::class)->allows($endpoint, (string) $request->ip(), $maximumAttempts);
        } catch (Throwable) {
            $allowed = true;
        }

        if (! $allowed) {
            return $this->tooManyRequests($endpoint);
        }

        return $next($request);
    }

    private function tooManyRequests(string $endpoint): JsonResponse
    {
        return response()->json(
            $endpoint === 'live'
                ? ['live' => false]
                : ['ready' => false, 'database' => false, 'id_server' => false, 'relay_server' => false],
            429,
        );
    }
}
