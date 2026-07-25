<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the RustDesk client CLI endpoints (`--assign` / `--deploy`) at the
 * bare `/api/devices/*` paths the client hardcodes. Kept in a dedicated
 * provider + route file so routes/api.php (owned by the main session) stays
 * untouched. Route definitions: routes/client-cli.php. Spec: docs/assign-protocol.md.
 */
class ClientCliRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api')->group(base_path('routes/client-cli.php'));
    }
}
