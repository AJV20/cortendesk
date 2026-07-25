<?php

return [
    // TLS proxies must be explicitly trusted. The default covers local and
    // private Docker/LAN proxy hops; set TRUSTED_PROXIES for another topology.
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'TRUSTED_PROXIES',
            '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
        )),
    ))),
];