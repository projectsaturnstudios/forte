<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Forte Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Forte to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. "forte" is the server; a Reverb .env that
    | still says REVERB_SERVER=reverb boots it too.
    |
    */

    'default' => env('FORTE_SERVER', env('REVERB_SERVER', 'forte')),

    /*
    |--------------------------------------------------------------------------
    | Forte Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Forte servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'forte' => [
            'host' => env('FORTE_SERVER_HOST', env('REVERB_SERVER_HOST', '0.0.0.0')),
            'port' => env('FORTE_SERVER_PORT', env('REVERB_SERVER_PORT', 8080)),
            'path' => env('FORTE_SERVER_PATH', env('REVERB_SERVER_PATH', '')),
            'hostname' => env('FORTE_HOST', env('REVERB_HOST')),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('FORTE_MAX_REQUEST_SIZE', env('REVERB_MAX_REQUEST_SIZE', 10_000)),
            'loop' => env('FORTE_LOOP'),
            'scaling' => [
                'enabled' => env('FORTE_SCALING_ENABLED', env('REVERB_SCALING_ENABLED', false)),
                'channel' => env('FORTE_SCALING_CHANNEL', env('REVERB_SCALING_CHANNEL', 'reverb')),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('FORTE_PULSE_INGEST_INTERVAL', env('REVERB_PULSE_INGEST_INTERVAL', 15)),
            'telescope_ingest_interval' => env('FORTE_TELESCOPE_INGEST_INTERVAL', env('REVERB_TELESCOPE_INGEST_INTERVAL', 15)),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Forte Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Forte applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('FORTE_APP_KEY', env('REVERB_APP_KEY')),
                'secret' => env('FORTE_APP_SECRET', env('REVERB_APP_SECRET')),
                'app_id' => env('FORTE_APP_ID', env('REVERB_APP_ID')),
                'options' => [
                    'host' => env('FORTE_HOST', env('REVERB_HOST')),
                    'port' => env('FORTE_PORT', env('REVERB_PORT', 443)),
                    'scheme' => env('FORTE_SCHEME', env('REVERB_SCHEME', 'https')),
                    'useTLS' => env('FORTE_SCHEME', env('REVERB_SCHEME', 'https')) === 'https',
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => env('FORTE_APP_PING_INTERVAL', env('REVERB_APP_PING_INTERVAL', 60)),
                'activity_timeout' => env('FORTE_APP_ACTIVITY_TIMEOUT', env('REVERB_APP_ACTIVITY_TIMEOUT', 30)),
                'max_connections' => env('FORTE_APP_MAX_CONNECTIONS', env('REVERB_APP_MAX_CONNECTIONS')),
                'max_message_size' => env('FORTE_APP_MAX_MESSAGE_SIZE', env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000)),
                'accept_client_events_from' => env('FORTE_APP_ACCEPT_CLIENT_EVENTS_FROM', env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members')),
                'rate_limiting' => [
                    'enabled' => env('FORTE_APP_RATE_LIMITING_ENABLED', env('REVERB_APP_RATE_LIMITING_ENABLED', false)),
                    'max_attempts' => env('FORTE_APP_RATE_LIMIT_MAX_ATTEMPTS', env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60)),
                    'decay_seconds' => env('FORTE_APP_RATE_LIMIT_DECAY_SECONDS', env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60)),
                    'terminate_on_limit' => env('FORTE_APP_RATE_LIMIT_TERMINATE', env('REVERB_APP_RATE_LIMIT_TERMINATE', false)),
                ],
            ],
        ],

    ],

];
