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
        // Max wall-clock duration allowed in CONNECTING (non-blocking).
        'connect_timeout' => 10,
        // Idle-read liveness threshold in seconds (non-blocking).
        'read_timeout' => 30,
        // Per-frame parser cap in bytes (bounded internally to 64KB..4MB).
        'max_frame_size' => 1048576,
        // Optional additions to default secret redaction key list/patterns/values.
        'redaction_keys' => [],
        'redaction_key_patterns' => [],
        'redaction_value_patterns' => [],
        // Max ActionID length (bounded internally to 64..256).
        'max_action_id_length' => 128,
        // Production hostname policy: true requires literal IP endpoints (no DNS in tick/reconnect paths).
        // If false, hostnames still require pre-resolved IPs or an injected resolver at bootstrap.
        'enforce_ip_endpoints' => true,
        'heartbeat_interval' => 15,
        // Circuit breaker: consecutive failure threshold before OPEN.
        'circuit_failure_threshold' => 5,
        // Circuit breaker: cooldown seconds before allowing probes.
        'circuit_cooldown' => 30,
        // Circuit breaker: max probe attempts while HALF_OPEN.
        'circuit_half_open_max_probes' => 1,
        'max_pending_actions' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Event Bridging
    |--------------------------------------------------------------------------
    |
    | When enabled, all AMI events will be dispatched via Laravel's native
    | event system. Disabled by default for performance in high-throughput.
    |
    */
    'bridge_laravel_events' => env('AMI_BRIDGE_LARAVEL_EVENTS', false),
];
