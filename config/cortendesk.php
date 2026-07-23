<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reported API version
    |--------------------------------------------------------------------------
    | Returned by GET /api/version. Clients may use this for capability hints.
    */
    // Single source of truth is the VERSION file at the repo root (also used
    // by release tags and the Docker image); env can still override.
    'api_version' => env('CORTENDESK_API_VERSION', trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0'),

    /*
    |--------------------------------------------------------------------------
    | RustDesk infrastructure this console fronts
    |--------------------------------------------------------------------------
    | The hbbs (ID/rendezvous) and hbbr (relay) servers, and the server's
    | public key (contents of id_ed25519.pub). Used for web-client bootstrap
    | and shown on the Settings screen.
    */
    'id_server' => env('CORTENDESK_ID_SERVER', ''),
    'relay_server' => env('CORTENDESK_RELAY_SERVER', ''),
    'public_key' => env('CORTENDESK_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Web client
    |--------------------------------------------------------------------------
    | Absolute URL of the static V1 web client (served by nginx, not this
    | app). Must be plain http — browsers block ws:// connections to
    | hbbs/hbbr from an https page. Empty hides the sidebar link.
    */
    'webclient_url' => env('CORTENDESK_WEBCLIENT_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Native web client (V2)
    |--------------------------------------------------------------------------
    | The in-browser client served by this app at /webclient (assets in
    | public/rdclient). WebSocket endpoints for hbbs/hbbr behind the nginx
    | TLS proxy; when empty they are derived from id_server as
    | wss://<host>/ws/id and wss://<host>/ws/relay. The flag gates the
    | "Connect" links in the device list.
    */
    'native_webclient' => env('CORTENDESK_NATIVE_WEBCLIENT', true),
    'ws_id_url' => env('CORTENDESK_WS_ID_URL', ''),
    'ws_relay_url' => env('CORTENDESK_WS_RELAY_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    | Seconds without a heartbeat before a device counts as offline.
    | Stock clients heartbeat roughly every 15 seconds.
    */
    'online_window' => env('CORTENDESK_ONLINE_WINDOW', 60),

    /*
    |--------------------------------------------------------------------------
    | Build Installers
    |--------------------------------------------------------------------------
    | URL the sidebar "Build Installers" entry opens (an rdgen instance).
    | Overridable per-install in Settings; empty hides the menu entry.
    */
    'rdgen_url' => env('CORTENDESK_RDGEN_URL', 'https://rdgen.crayoneater.org'),
];
