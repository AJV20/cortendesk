<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Web-client bootstrap script (loaded by the static V1 web client pre-login)
Route::get('/webclient-config/index.js', [\App\Http\Controllers\Api\WebClientController::class, 'configJs']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('overview');

    Route::view('/devices', 'devices.index')->name('devices');
    Route::view('/address-books', 'address-books.index')->name('address-books');

    // Native in-browser RustDesk client (full-viewport standalone page; assets in public/rdclient/)
    Route::get('/webclient', [\App\Http\Controllers\WebClientPageController::class, 'show'])->name('webclient');

    Route::view('/logs/connections', 'logs.connections')->name('logs.connections');
    Route::view('/logs/file-transfers', 'logs.file-transfers')->name('logs.file-transfers');
    Route::view('/logs/alarms', 'logs.alarms')->name('logs.alarms');

    // Admin-only sections
    Route::middleware('admin')->group(function () {
        Route::view('/groups', 'groups.index')->name('groups');
        Route::view('/users', 'users.index')->name('users');
        Route::view('/logs/logins', 'logs.logins')->name('logs.logins');
        Route::view('/logs/console', 'logs.console')->name('logs.console');
        Route::view('/settings', 'settings.index')->name('settings');
    });
});
