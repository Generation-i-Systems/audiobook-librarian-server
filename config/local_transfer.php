<?php

declare(strict_types=1);

return [
    /*
    | Comma-separated https:// addresses of this server's LAN listener, as devices on the
    | same network reach it (for example https://192.168.1.20:8443). The apps download book
    | files from the first one that answers and fall back to APP_URL. Empty turns it off.
    */
    'urls' => env('LOCAL_URL', ''),

    /*
    | The LAN listener's certificate. The apps trust only its public key, which they learn
    | over the APP_URL connection, so it can be self-signed. The Docker image generates one
    | here on first start.
    */
    'cert_path' => env('LOCAL_TLS_CERT', storage_path('app/local-tls/cert.pem')),
];
