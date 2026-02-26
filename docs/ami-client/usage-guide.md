# AMI Client Usage Guide

This guide provides a comprehensive overview of how to integrate and use the `apn/ami-client` package in a Laravel 12 application.

## 1. Overview

The `apn/ami-client` package is a high-performance, non-blocking Asterisk Manager Interface (AMI) client designed for long-lived CLI workers and dialer-grade applications.

### Key Features:
- **Multi-Server Support**: Manage connections to multiple Asterisk nodes via a single manager.
- **Non-Blocking I/O**: Driven by a deterministic tick loop, ensuring responsiveness even under high load.
- **Typed Actions**: Built-in support for common AMI actions like `Login`, `Ping`, `Originate`, and `Command`.
- **Event-Driven**: Flexible event subscription model for handling Asterisk events.
- **Production Ready**: Includes backpressure handling, circuit breakers, and exponential backoff for reconnections.

---

## 2. Installation

Install the package via Composer:

```bash
composer require apn/ami-client
```

The package will automatically register its Service Provider and Facade in Laravel 12.

### Publishing Configuration

Publish the default configuration file to `config/ami-client.php`:

```bash
php artisan vendor:publish --tag=ami-config
```

---

## 3. Configuration

The configuration file allows you to define multiple Asterisk servers and global options.

### Example `config/ami-client.php`

```php
return [
    // Default server used when no specific server is requested
    'default' => env('AMI_DEFAULT_SERVER', 'node_1'),

    'servers' => [
        'node_1' => [
            'host' => env('AMI_NODE1_HOST', '127.0.0.1'),
            'port' => env('AMI_NODE1_PORT', 5038),
            'username' => env('AMI_NODE1_USERNAME', 'admin'),
            'secret' => env('AMI_NODE1_SECRET', 'amp111'),
            'timeout' => 30,
            // TLS Support (requires Asterisk configured for AMI-over-TLS)
            'tls' => env('AMI_NODE1_TLS', false),
        ],
        'node_2' => [
            'host' => env('AMI_NODE2_HOST', '10.0.0.2'),
            'port' => env('AMI_NODE2_PORT', 5038),
            'username' => env('AMI_NODE2_USERNAME', 'admin'),
            'secret' => env('AMI_NODE2_SECRET', 'amp111'),
            'timeout' => 30,
        ],
    ],

    'options' => [
        // Max wall-clock duration allowed in CONNECTING (non-blocking).
        'connect_timeout' => 10,
        // Idle-read liveness threshold in seconds (non-blocking).
        'read_timeout' => 30,
        // Per-frame parser cap in bytes (bounded internally to 64KB..4MB).
        'max_frame_size' => 1048576,
        // Optional additions to default secret redaction key list/patterns.
        'redaction_keys' => [],
        'redaction_key_patterns' => [],
        // Max ActionID length (bounded internally to 64..256).
        'max_action_id_length' => 128,
        // Production policy: require literal IP endpoints to avoid DNS in connect/tick hot paths.
        'enforce_ip_endpoints' => false,
        'heartbeat_interval' => 15,
        // Circuit breaker: consecutive failure threshold before OPEN.
        'circuit_failure_threshold' => 5,
        // Circuit breaker: cooldown seconds before allowing probes.
        'circuit_cooldown' => 30,
        // Circuit breaker: max probe attempts while HALF_OPEN.
        'circuit_half_open_max_probes' => 1,
        'max_pending_actions' => 5000,
        'event_queue_capacity' => 10000,
        'write_buffer_limit' => 5242880, // 5MB
    ],

    // Dispatch AMI events via Laravel's native event system
    'bridge_laravel_events' => env('AMI_BRIDGE_LARAVEL_EVENTS', false),
];
```

### Recommended `.env` variables

```env
AMI_DEFAULT_SERVER=node_1
AMI_NODE1_HOST=127.0.0.1
AMI_NODE1_PORT=5038
AMI_NODE1_USERNAME=admin
AMI_NODE1_SECRET=amp111
AMI_BRIDGE_LARAVEL_EVENTS=false
```

---

## 4. Basic Usage (Single Server)

You can interact with the default AMI server using the `Ami` facade or by injecting `AmiClientManager`.

### Sending Simple Actions

```php
use Apn\AmiClient\Laravel\Ami;
use Apn\AmiClient\Protocol\Ping;

// Send a Ping action to the default server
Ami::default()->send(new Ping())->onComplete(function ($e, $response) {
    if ($response?->isSuccess()) {
        // Handling success
    }
});
```

### Get/Set Variables

```php
use Apn\AmiClient\Protocol\GetVar;
use Apn\AmiClient\Protocol\SetVar;

// Set a channel variable
Ami::default()->send(new SetVar(
    variable: 'MY_VAR',
    value: '123',
    channel: 'PJSIP/101-00000001'
));

// Get a channel variable
Ami::default()->send(new GetVar(
    variable: 'MY_VAR',
    channel: 'PJSIP/101-00000001'
))->onComplete(function ($e, $response) {
    if ($response) {
        $value = $response->getHeader('Value');
    }
});
```

---

## 5. Multi-Server Usage

The `AmiClientManager` handles routing and server selection.

### Targeting Specific Servers

```php
use Apn\AmiClient\Laravel\Ami;

// Send to a specific server
Ami::server('node_2')->send(new Ping());

// Use the default server
Ami::default()->send(new Ping());
```

### Managed Loop for All Servers

In a long-lived worker, you should tick all connections to handle I/O and timeouts.

```php
while (true) {
    Ami::tickAll(timeoutMs: 100);
}
```

---

## 6. Originate Example (Async Completion)

`Originate` is often used to initiate calls. It can return an immediate response (success/failure of the request) and later trigger events indicating the call outcome.

```php
use Apn\AmiClient\Protocol\Originate;
use Apn\AmiClient\Exceptions\AmiTimeoutException;

try {
    $pending = Ami::default()->send(new Originate(
        channel: 'PJSIP/101',
        exten: '500',
        context: 'default',
        priority: 1,
        async: true
    ));

    $pending->onComplete(function ($exception, $response) {
        if ($exception) {
            // The request itself timed out or failed
        }
        
        if ($response?->isSuccess()) {
            // Asterisk accepted the originate request
        }
    });
} catch (AmiTimeoutException $e) {
    // This could be thrown if the write queue is full and a timeout is triggered
}
```

> **Note**: For tracking the actual call state (e.g., when the call is answered), you should subscribe to `OriginateResponse` or `Newchannel` events using the generated `ActionID`.

---

## 7. Command / Generic Action Example

### Running CLI Commands

The `Command` action handles multi-line "Follows" responses automatically.

```php
use Apn\AmiClient\Protocol\Command;

Ami::default()->send(new Command('core show channels'))->onComplete(function ($e, $response) {
    $output = $response?->getHeader('Output'); // Array of lines or raw string
});
```

### Generic Actions

For actions not yet explicitly typed in the package, use `GenericAction`.

```php
use Apn\AmiClient\Protocol\GenericAction;

Ami::default()->send(new GenericAction('QueueSummary', [
    'Queue' => 'support'
]))->onComplete(function ($e, $response) {
    // Handle response
});
```

---

## 8. Event Handling & Subscriptions

You can subscribe to events globally via the manager.

### Specific Event Subscription

```php
use Apn\AmiClient\Laravel\Ami;
use Apn\AmiClient\Events\AmiEvent;

Ami::onEvent('Hangup', function (AmiEvent $event) {
    Log::info('Channel hung up', [
        'channel' => $event->getHeader('Channel'),
        'cause' => $event->getHeader('Cause-txt'),
    ]);
});
```

### Catch-All Subscription

```php
Ami::onAnyEvent(function (AmiEvent $event) {
    // Process every event from every server
});
```

> **Backpressure Warning**: Listeners must be non-blocking. If you need to perform heavy processing (e.g., DB writes), dispatch a Laravel Job.

---

## 9. Running Workers (Production Pattern)

For production, run a dedicated worker process for each server using the provided artisan command.

### Recommended Dedicated `ami:listen` Pattern (Event Bridge)

For dialer-grade stability in Laravel, use a single dedicated `ami:listen` process that maintains AMI connections and publishes events to a low-latency transport (Redis `PUBLISH` or a dedicated event bus). Other workers should consume from that transport instead of connecting directly to AMI.

This pattern prevents connection explosions and keeps AMI I/O isolated in a single, long-lived process.

### Warning: Connection Explosion in Laravel Topology

Running `N` queue workers against `M` AMI nodes creates `N * M` connections. This can exhaust `manager.conf` limits and introduce high overhead. Use the Event Bridge pattern unless the process count is strictly bounded and the AMI handshake cost is acceptable.

### Artisan Command

```bash
# Listen to node_1
php artisan ami:listen --server=node_1
```

### Supervisor Configuration Example

```ini
[program:ami-worker-node1]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan ami:listen --server=node_1
autostart=true
autorestart=true
user=www-data
numprocs=1
stopwaitsecs=30
```

### Graceful Shutdown

The `ami:listen` command automatically handles `SIGTERM` and `SIGINT`, ensuring outbound buffers are flushed and connections are closed cleanly.

### Redis Event Bridge Example (Configuration + Publisher)

Use Redis pub/sub to fan out events from the dedicated listener to other workers. This example keeps AMI connections centralized.

```env
AMI_EVENT_BRIDGE=redis
AMI_REDIS_CHANNEL=ami.events
```

```php
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Laravel\Ami;
use Illuminate\Support\Facades\Redis;

// In a bootstrapped service provider or the ami:listen command handler
Ami::onAnyEvent(function (AmiEvent $event): void {
    $payload = [
        'server' => $event->getServerKey(),
        'name' => $event->getName(),
        'headers' => $event->getHeaders(),
        'received_at' => $event->getReceivedAt(),
    ];

    Redis::publish(env('AMI_REDIS_CHANNEL', 'ami.events'), json_encode($payload));
});
```

On the consumer side, subscribe to the channel and dispatch jobs or events as needed.

--- 

## 10. Observability

### Structured Logging

The package emits JSON-formatted logs. Ensure your logger is configured to capture:
- `server_key`
- `action_id`
- `queue_depth` (normalized to `null` for non-queue logs)
- `worker_pid`

### Metrics

If a `MetricsCollector` is configured, the following metrics are tracked:
- `ami_action_latency_ms`: Histogram of action execution time.
- `ami_event_count`: Counter of received events per type.
- `ami_dropped_events`: Counter of events dropped due to queue capacity.
- `ami_connection_status`: Gauge (1 for healthy, 0 for disconnected).

---

## 11. Best Practices (Dialer-Grade)

- **No Blocking in Listeners**: Never use `sleep()` or perform blocking I/O inside an `onEvent` callback.
- **Bounded Queues**: Always configure `event_queue_capacity` and `write_buffer_limit` to prevent OOM.
- **Endpoint Policy**: In production, set `enforce_ip_endpoints=true` or pre-resolve hostnames during bootstrap.
- **Health Validation**: Check `Ami::server('node_1')->getHealthStatus()->isAvailable()` before dispatching critical actions.
- **Secret Redaction**: The package automatically masks `Secret` and `Password` in logs. Avoid logging sensitive `Variable` values manually.

---

## 12. Troubleshooting

| Issue | Potential Cause |
| :--- | :--- |
| **Auth Failures** | Incorrect `username` or `secret`; missing `read` or `write` permissions in `manager.conf`. |
| **No Events Received** | `read` permissions missing in Asterisk; server is in `DEGRADED` state. |
| **High CPU Usage** | `timeoutMs` in `tick()` or `tickAll()` is set to 0 in a tight loop without other work. |
| **Memory Growth** | Large number of pending actions timing out; ensure `max_pending_actions` is bounded. |
| **Queue Drops** | `event_queue_capacity` exceeded. Increase capacity or speed up event processing. |
| **TLS Failures** | Certificate mismatch or Asterisk not configured for TLS on the specified port. |
