<?php

use App\Http\Controllers\Api\AddressBookController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\GroupTabController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RustDesk client API
|--------------------------------------------------------------------------
| Implements the HTTP contract the official RustDesk client speaks when
| configured with this server as its "API Server".
| Spec: docs/client-api.md (extracted from rustdesk/rustdesk source).
|
| Contract reminders:
| - Tokenless endpoints (login, heartbeat, sysinfo, audit) get no Bearer.
| - Many callers ignore HTTP status and only parse the body; errors are
|   signaled as {"error": "..."} even on HTTP 200.
| - New-AB mutations signal success with an EMPTY 200 body.
*/

Route::get('/version', fn () => response(config('cortendesk.api_version')));

// ---- Tokenless (rate-limited) ------------------------------------------
Route::middleware('throttle:240,1')->group(function () {
    Route::match(['get', 'head'], '/login-options', [ClientAuthController::class, 'loginOptions']);
    Route::post('/login', [ClientAuthController::class, 'login'])->middleware('throttle:20,1');

    Route::post('/heartbeat', [SyncController::class, 'heartbeat']);
    Route::post('/sysinfo', [SyncController::class, 'sysinfo']);

    Route::post('/audit/conn', [AuditController::class, 'connection']);
    Route::post('/audit/file', [AuditController::class, 'file']);
    Route::post('/audit/alarm', [AuditController::class, 'alarm']);
});

// ---- Bearer-token endpoints ---------------------------------------------
Route::middleware('auth:client')->group(function () {
    Route::post('/logout', [ClientAuthController::class, 'logout']);
    Route::post('/currentUser', [ClientAuthController::class, 'currentUser']);
    Route::put('/audit', [AuditController::class, 'note']);

    // Legacy address book (client < 1.2.6, Sciter)
    Route::get('/ab', [AddressBookController::class, 'legacyGet']);
    Route::post('/ab/get', [AddressBookController::class, 'legacyGet']); // Sciter alias
    Route::post('/ab', [AddressBookController::class, 'legacyPush']);

    // New multi-address-book API (client >= 1.2.6)
    Route::post('/ab/personal', [AddressBookController::class, 'personal']);
    Route::post('/ab/settings', [AddressBookController::class, 'settings']);
    Route::post('/ab/shared/profiles', [AddressBookController::class, 'sharedProfiles']);
    Route::post('/ab/peers', [AddressBookController::class, 'peers']);
    Route::post('/ab/tags/{guid}', [AddressBookController::class, 'tags']);
    Route::post('/ab/peer/add/{guid}', [AddressBookController::class, 'peerAdd']);
    Route::put('/ab/peer/update/{guid}', [AddressBookController::class, 'peerUpdate']);
    Route::delete('/ab/peer/{guid}', [AddressBookController::class, 'peerDelete']);
    Route::post('/ab/tag/add/{guid}', [AddressBookController::class, 'tagAdd']);
    Route::put('/ab/tag/rename/{guid}', [AddressBookController::class, 'tagRename']);
    Route::put('/ab/tag/update/{guid}', [AddressBookController::class, 'tagUpdate']);
    Route::delete('/ab/tag/{guid}', [AddressBookController::class, 'tagDelete']);

    // Web client bootstrap (V1 static client served at /webclient/)
    Route::match(['get', 'post'], '/server-config', [\App\Http\Controllers\Api\WebClientController::class, 'serverConfig']);

    // Group tab
    Route::get('/device-group/accessible', [GroupTabController::class, 'deviceGroups']);
    Route::get('/users', [GroupTabController::class, 'users']);
    Route::get('/peers', [GroupTabController::class, 'peers']);
});
