<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Identify a Livewire component round-trip the way Livewire itself does: by
 * the route name, which always ends in `livewire.update` (Livewire 4 names
 * its default route `default-livewire.update`; custom registrations keep the
 * suffix).
 *
 * Middleware that walks a signed-in user to a fix-it screen (2FA enrollment,
 * mail repair, missing email address) MUST let these through: every button on
 * that screen is a wire:click, so redirecting the round-trip turns the screen
 * into a dead end that "flashes" and reloads itself (#18 — a user who switched
 * 2FA enforcement on before enrolling could never reach the QR code).
 *
 * A literal `livewire/*` path check does NOT work and never fired: Livewire 4
 * serves its routes under a per-release hashed prefix (`/livewire-<hash>/…`).
 * Match the route name, not the path — the name is the contract Livewire's own
 * route discovery relies on (HandleRequests::findUpdateRoute).
 */
class LivewireRequest
{
    public static function isComponentUpdate(Request $request): bool
    {
        return (bool) $request->route()?->named('*livewire.update');
    }
}
