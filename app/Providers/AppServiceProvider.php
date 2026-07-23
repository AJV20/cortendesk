<?php

namespace App\Providers;

use App\Models\ClientToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Guard for the RustDesk client API: Authorization: Bearer <token>
        // issued by POST /api/login and stored in client_tokens.
        Auth::viaRequest('client-token', function (Request $request) {
            $bearer = $request->bearerToken();
            if (! $bearer) {
                return null;
            }

            $token = ClientToken::query()->where('token', $bearer)->first();
            if (! $token || ! $token->isValid()) {
                return null;
            }

            if (! $token->user || ! $token->user->is_active) {
                return null;
            }

            $token->forceFill(['last_used_at' => now()])->saveQuietly();
            $request->attributes->set('client_token', $token);

            return $token->user;
        });
    }
}
