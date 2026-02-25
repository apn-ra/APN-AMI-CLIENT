# AMI Client for Laravel 12

## 1) Overview

The `apn/ami-client` package is a high-performance, non-blocking Asterisk Manager Interface (AMI) client specifically designed for Laravel 12 and PHP 8.4+. 

It is built for "dialer-grade" applications where stability and throughput are critical. Unlike traditional blocking AMI clients, this package uses a non-blocking I/O multiplexing model, making it ideal for:
- **High-throughput dialers:** Managing hundreds of concurrent calls.
- **Asterisk Clusters:** Seamlessly interacting with multiple Asterisk nodes.
- **Long-lived Workers:** Stable CLI processes that listen for events 24/7 without memory leaks.

### High-Level Architecture
- **Core Layer:** Framework-agnostic logic for protocol framing, parsing, and correlation.
- **Transport Layer:** Non-blocking TCP socket management using `stream_select`.
- **Correlation Engine:** Maps ActionIDs to Responses and manages their lifecycle.
- **Laravel Bridge:** Provides Facades, Service Providers, and Artisan commands for deep integration.

## 2) Installation

Install the package via Composer:

```bash
composer require apn/ami-client
```

### Service Provider
The package uses Laravel 12's auto-discovery. If you need to register it manually, add the service provider to your `bootstrap/providers.php` (for Laravel 11+) or `config/app.php`:

```php
return [
    Apn\AmiClient\Laravel\AmiClientServiceProvider::class,
];
```

### Publishing Configuration
Publish the configuration file to your `config` directory:

```bash
php artisan vendor:publish --tag=ami-config
```

## 3) Configuration

The configuration file `config/ami-client.php` allows you to define multiple server nodes and global defaults.

### Example Configuration

```php
<?php

declare(strict_types=1);

return [
    'default' => env('AMI_DEFAULT_SERVER', 'node_1'),

    'servers' => [
        'node_1' => [
            'host' => env('AMI_NODE1_HOST', '127.0.0.1'),
            'port' => env('AMI_NODE1_PORT', 5038),
            'username' => env('AMI_NODE1_USERNAME'),
            'secret' => env('AMI_NODE1_SECRET'),
            'connect_timeout' => 10,
        ],
        'node_2' => [
            'host' => env('AMI_NODE2_HOST', '127.0.0.1'),
            'port' => env('AMI_NODE2_PORT', 5038),
            'username' => env('AMI_NODE2_USERNAME'),
            'secret' => env('AMI_NODE2_SECRET'),
            'connect_timeout' => 10,
        ],
    ],

    'options' => [
        'connect_timeout' => 10,
        'max_pending_actions' => 5000,
        'event_queue_capacity' => 10000,
        'memory_limit' => 128 * 1024 * 1024, // 128MB
        'blocked_events' => ['FullyBooted', 'RTCPSent'],
    ],
];
```

### Recommended .env Variables

```env
AMI_DEFAULT_SERVER=node_1

AMI_NODE1_HOST=10.0.0.5
AMI_NODE1_USERNAME=admin
AMI_NODE1_SECRET=your_secure_password

AMI_NODE2_HOST=10.0.0.6
AMI_NODE2_USERNAME=admin
AMI_NODE2_SECRET=your_secure_password
```

## 4) Basic Usage (Single Server)

You can interact with the default AMI server using the `Ami` facade or by type-hinting `AmiClientManager`.

### Sending Actions

```php
use Apn\AmiClient\Laravel\Ami;
use Apn\AmiClient\Protocol\Ping;
use Apn\AmiClient\Protocol\SetVar;
use Apn\AmiClient\Protocol\GetVar;

// Ping the server
Ami::default()->send(new Ping())
    ->onComplete(function (?Throwable $e, ?Response $r) {
        if ($r?->isSuccess()) {
            dump("Pong received!");
        }
    });

// Set a channel variable
Ami::send(new SetVar(
    variable: 'MY_VAR', 
    value: '123', 
    channel: 'PJSIP/101-00000001'
));

// Execution requires a tick
Ami::default()->tick();
```

## 5) Multi-Server Usage

The `AmiClientManager` (via the `Ami` facade) makes it easy to route actions to specific nodes.

```php
use Apn\AmiClient\Laravel\Ami;
use Apn\AmiClient\Protocol\Ping;

// Send to a specific server
Ami::server('node_b')->send(new Ping());

// Use a specific routing strategy (e.g. Round Robin)
$client = Ami::routing(new RoundRobinRoutingStrategy())->select();
$client->send(new Ping());

// Execute processing for ALL servers in a loop
while (true) {
    Ami::tickAll(100); // 100ms timeout
}
```

## 6) Originate Example (Async Completion)

AMI `Originate` is complex because it returns an immediate "Response" (stating if the action was accepted) and later an "OriginateResponse" event (stating if the call actually connected).

```php
use Apn\AmiClient\Laravel\Ami;
use Apn\AmiClient\Protocol\Originate;

$action = new Originate(
    channel: 'PJSIP/101',
    exten: '200',
    context: 'from-internal',
    priority: 1,
    async: true,
    variables: ['source' => 'api_call']
);

Ami::send($action)->onComplete(function ($e, $response) {
    if ($e) {
        // Handle timeout or connection loss
        return;
    }
    
    if ($response->isSuccess()) {
        // Action accepted by Asterisk
    }
});
```

To capture the final result, subscribe to the `OriginateResponse` event:

```php
Ami::onEvent('OriginateResponse', function ($event) {
    if ($event->get('Response') === 'Success') {
        Log::info("Call connected!", ['Channel' => $event->get('Channel')]);
    }
});
```

## 7) Command / Generic Action Example

For actions not yet typed in the package, use `GenericAction`. For CLI commands, use `Command`.

### Command (Response: Follows)

```php
use Apn\AmiClient\Protocol\Command;

Ami::send(new Command('pjsip show endpoints'))
    ->onComplete(function ($e, $r) {
        // 'Follows' responses contain the CLI output in the 'RawOutput' parameter
        $output = $r->get('RawOutput');
        dump($output);
    });
```

### GenericAction

```php
use Apn\AmiClient\Protocol\GenericAction;

$action = new GenericAction('QueueSummary', [
    'Queue' => 'support'
]);

Ami::send($action);
```

## 8) Event Handling & Subscriptions

Subscriptions allow you to react to Asterisk events in real-time.

```php
use Apn\AmiClient\Laravel\Ami;

// Listen for a specific event
Ami::onEvent('Hangup', function ($event) {
    $channel = $event->get('Channel');
    $cause = $event->get('Cause-txt');
    
    Log::info("Channel hung up", compact('channel', 'cause'));
});

// Catch-all listener
Ami::onAnyEvent(function ($event) {
    if ($event->getName() === 'Newchannel') {
        // Handle new channel
    }
});
```

> **Warning:** Listeners are executed within the main `tick()` loop. **Never perform blocking operations** (like slow database queries or external API calls) inside a listener. Use Laravel's `dispatch()` to offload heavy work to a queue.

## 9) Running Workers (Production Pattern)

For event listening and background processing, run a dedicated Artisan worker for each server.

### Artisan Command
```bash
# Listen to a specific server
php artisan ami:listen --server=node_1

# Listen to all servers (multiplexed in one process)
php artisan ami:listen --all
```

### Supervisor Example
Ensure your workers are automatically restarted by Supervisor:

```ini
[program:ami-worker-node1]
command=php /var/www/artisan ami:listen --server=node_1
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/ami-worker.log
```

## 10) Observability

The package uses structured JSON logging and supports Prometheus-compatible metrics.

### Structured Logging
Logs include:
- `server_key`: The ID of the node.
- `action_id`: Correlates logs with specific AMI actions.
- `worker_pid`: Identifies the worker process.

### Metrics to Monitor
If you implement a metrics collector, track:
- `ami_dropped_events`: Indicates if your listeners are too slow (backpressure).
- `ami_action_latency_ms`: Histogram of action execution time.
- `ami_connection_status`: 1 for healthy, 0 for disconnected.

## 11) Best Practices (Dialer-Grade)

1. **No Blocking in Listeners:** Always dispatch heavy tasks to Laravel's queue.
2. **Bounded Queues:** Monitor `dropped_events` to ensure your event loop is keeping up.
3. **Health Validation:** Use `Ami::server('node_1')->isConnected()` before sending critical actions.
4. **No Secret Logging:** The package automatically masks `Secret` and `Password` fields in logs. Never disable this in production.
5. **Use Multi-Server Isolation:** If one Asterisk node hangs, the `AmiClientManager` ensures other nodes remain reachable.

## 12) Troubleshooting

### Auth Failures
- Verify `username` and `secret` in `config/ami-client.php`.
- Check Asterisk's `manager.conf` for correct permissions (`read`, `write`) and IP whitelisting.

### No Events Received
- Ensure you have sent the `Login` action (handled automatically by `AmiClientManager`).
- Check if your user in `manager.conf` has `read = all` or specific event privileges.

### High CPU Usage
- Usually caused by a tight loop with a 0ms timeout. Ensure `tick(100)` or similar is used in your workers to allow the CPU to rest.

### Memory Growth
- The client is audited for zero memory growth. If you see leaks, check your custom event listeners for static arrays or global variables that grow over time.

### Queue Drops
- If `ami_dropped_events` increases, it means the worker is processing events slower than Asterisk is sending them. Optimize your listeners or reduce the number of events subscribed to via `blocked_events` in config.
