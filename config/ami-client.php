<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Server
    |--------------------------------------------------------------------------
    |
    | This is the default server key used when no specific server is requested.
    |
    */
    'default' => env('AMI_DEFAULT_SERVER', 'node_1'),

    /*
    |--------------------------------------------------------------------------
    | Asterisk Servers (Nodes)
    |--------------------------------------------------------------------------
    |
    | Define all Asterisk nodes here. Each node must have a unique key.
    |
    */
    'servers' => [
        'node_1' => [
            'host' => env('AMI_NODE1_HOST', '127.0.0.1'),
            'port' => env('AMI_NODE1_PORT', 5038),
            'username' => env('AMI_NODE1_USERNAME', 'admin'),
            'secret' => env('AMI_NODE1_SECRET', 'amp111'),
            'timeout' => 30,
            'write_buffer_limit' => 5242880, // 5MB
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings applied to all connections unless overridden.
    |
    */
    'options' => [
        'connect_timeout' => 10,
        'read_timeout' => 30,
        'heartbeat_interval' => 15,
        'max_pending_actions' => 5000,
    ],
];
