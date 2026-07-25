<?php

use App\Http\Controllers\Api\DeviceCliController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RustDesk client CLI endpoints (--assign / --deploy)
|--------------------------------------------------------------------------
| The official client posts these to `{api-server}/api/devices/*` with a
| Bearer API token (B1 `api_tokens`, guard `auth:api-token`). They sit at the
| bare `/api/devices/...` path the client hardcodes — NOT under the `/api/v1`
| admin surface — so they live in their own file loaded by
| ClientCliRouteServiceProvider (keeps routes/api.php untouched).
|
| Spec: docs/assign-protocol.md.  Response convention: empty 200 body = success
| (client prints "Done!"); a non-empty body is printed verbatim on error.
*/

Route::middleware(['auth:api-token', 'throttle:120,1'])
    ->prefix('devices')
    ->group(function () {
        // B2 — `rustdesk --assign`. Base assign needs Device rw; any
        // address_book_* field additionally needs address_book rw (checked in
        // the controller).
        Route::post('/cli', [DeviceCliController::class, 'assign'])
            ->middleware('api-token-can:device,rw');
    });
