<?php

return [
    // Kept separate because liveness is inexpensive while readiness opens
    // database and TCP dependency checks.
    'probe_limits' => [
        'live' => 120,
        'ready' => 30,
    ],
];
