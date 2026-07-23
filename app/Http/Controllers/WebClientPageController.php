<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class WebClientPageController extends Controller
{
    /**
     * Full-screen native web client page. WebSocket endpoints come from
     * explicit config when set, otherwise derived from the ID server host
     * (production terminates TLS at the nginx proxy: wss://<host>/ws/*).
     */
    public function show(Request $request): View
    {
        $wsIdUrl = config('cortendesk.ws_id_url');
        $wsRelayUrl = config('cortendesk.ws_relay_url');

        if (! $wsIdUrl || ! $wsRelayUrl) {
            $host = $this->idServerHost();
            $wsIdUrl = $wsIdUrl ?: ($host ? "wss://{$host}/ws/id" : '');
            $wsRelayUrl = $wsRelayUrl ?: ($host ? "wss://{$host}/ws/relay" : '');
        }

        $user = $request->user();

        return view('webclient', [
            'peerId' => (string) $request->query('id', ''),
            'serverKeyB64' => (string) config('cortendesk.public_key'),
            'wsIdUrl' => $wsIdUrl,
            'wsRelayUrl' => $wsRelayUrl,
            'myId' => 'web-'.$user->id,
            'myName' => $user->name ?: ($user->username ?? 'CortenDesk'),
        ]);
    }

    /** Hostname of the configured ID server, without any :port suffix. */
    private function idServerHost(): string
    {
        $server = trim((string) config('cortendesk.id_server'));
        if ($server === '') {
            return '';
        }

        // Accept "host", "host:port", or a full URL.
        if (str_contains($server, '://')) {
            return (string) (parse_url($server, PHP_URL_HOST) ?: '');
        }

        return explode(':', $server, 2)[0];
    }
}
