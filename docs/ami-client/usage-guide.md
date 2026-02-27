# AMI Client Usage Guide

This guide covers Laravel 12 and pure PHP usage of `apntalk/ami-client`.

Production readiness: **98 / 100** as of the latest audit (`docs/audit/src-production-readiness-audit-014.md`). This is a point-in-time measurement.

## 1) What This Package Is / Isn’t

**Is:**
- Dialer-grade, long-lived, multi-server AMI client.
- Non-blocking tick-driven runtime with bounded buffers and backpressure.
- Framework-agnostic core with a Laravel adapter (`src/Laravel`).

**Isn’t:**
- A web-request scoped client.
- A place to run blocking I/O inside event callbacks.
- A system that tolerates unbounded queues or blocking DNS in runtime paths.

## 2) Installation

```bash
composer require apntalk/ami-client
```

Requirements:
- PHP `>=8.4`

No PHP extensions are explicitly required beyond standard networking and streams.

## 3) Configuration

### Laravel config publishing

```bash
php artisan vendor:publish --tag=ami-config
```

### Minimal `config/ami-client.php` structure

```php
return [
    'default' => env('AMI_DEFAULT_SERVER', 'node_1'),

    'servers' => [
        'node_1' => [
            'host' => env('AMI_NODE1_HOST', '127.0.0.1'),
            'port' => env('AMI_NODE1_PORT', 5038),
            'username' => env('AMI_NODE1_USERNAME', 'admin'),
            'secret' => env('AMI_NODE1_SECRET', 'amp111'),
            'timeout' => 30,
            'write_buffer_limit' => 5242880,
        ],
    ],

    'options' => [
        'connect_timeout' => 10,
        'read_timeout' => 30,
        'heartbeat_interval' => 15,
        'max_frame_size' => 1048576,
        'max_action_id_length' => 128,
        'redaction_keys' => [],
        'redaction_key_patterns' => [],
        'redaction_value_patterns' => [],
        'enforce_ip_endpoints' => true,
        'event_drop_log_interval_ms' => 1000,
        'circuit_failure_threshold' => 5,
        'circuit_cooldown' => 30,
        'circuit_half_open_max_probes' => 1,
        'max_pending_actions' => 5000,
    ],

    'bridge_laravel_events' => env('AMI_BRIDGE_LARAVEL_EVENTS', false),

    'listen' => [
        'tick_timeout_ms' => env('AMI_LISTEN_TICK_TIMEOUT_MS', 25),
    ],
];
```

Additional supported options (same `options` array):
- `event_queue_capacity`
- `write_buffer_limit`

### Endpoint policy (DNS safety)

By default, `enforce_ip_endpoints` is `true`. This requires literal IPs in `servers.*.host` and prevents blocking DNS in tick/reconnect paths.

If you must use hostnames:
- Set `enforce_ip_endpoints` to `false`.
- Provide pre-resolved IPs or inject a hostname resolver when bootstrapping `AmiClientManager` (pure PHP via `ConfigLoader::load(..., hostnameResolver: $resolver)`).

Laravel’s default service provider does not inject a hostname resolver, so you must override the binding if you want hostname resolution.

## 4) Runtime Modes (Most Important)

### Profile A: Pure PHP worker

```php
use Apn\AmiClient\Cluster\ConfigLoader;
use Psr\Log\NullLogger;

$config = require __DIR__ . '/config/ami-client.php';

$manager = ConfigLoader::load($config, new NullLogger());

while (true) {
    $manager->tickAll(25); // bounded selector wait
}
```

### Profile B: Laravel artisan worker (`ami:listen`)

Basic usage:

```bash
php artisan ami:listen --server=node_1
```

Listen to all configured servers:

```bash
php artisan ami:listen --all
```

Bounded-run options (useful for supervised or test harnesses):
- `--once`
- `--max-iterations=1000`
- `--tick-timeout-ms=25`

Cadence behavior:
- The worker calls `tick()`/`tickAll()` with the configured timeout.
- If a tick makes no progress, it sleeps the remaining cadence window to avoid CPU hot-spin.

### Profile C: Embedded tick mode

Use this when you already have a loop or reactor. Use `pollAll()` or `tickAll(0)` so the AMI client never blocks your loop.

```php
// inside your own loop
$manager->pollAll(); // alias for tickAll(0)
```

## 5) Multi-Server Usage

### Targeting a specific server

```php
use Apn\AmiClient\Laravel\Ami;
use Apn\AmiClient\Protocol\Ping;

Ami::server('node_1')->send(new Ping());
Ami::default()->send(new Ping());
```

Manager-based access (pure PHP or DI):

```php
use Apn\AmiClient\Cluster\AmiClientManager;

$manager->server('node_1')->send(new Ping());
$manager->default()->send(new Ping());
```

### Routing strategies

Supported strategies:
- `RoundRobinRoutingStrategy`
- `FailoverRoutingStrategy`
- `ExplicitRoutingStrategy`

These strategies are health-aware: they only select servers in `READY` and throw if none are available.

```php
use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\Routing\RoundRobinRoutingStrategy;

$manager->routing(new RoundRobinRoutingStrategy());
```

For Laravel, bind the routing strategy in your service container if you want to override the default.

## 6) Actions

### Typed actions

```php
use Apn\AmiClient\Protocol\QueueStatus;

Ami::default()->send(new QueueStatus())
    ->onComplete(function (?\Throwable $e, $response, array $events): void {
        if ($e) {
            // Timeout, backpressure, or transport failure
            return;
        }
        // $response is the final AMI response
        // $events contains QueueStatus events collected by the strategy
    });
```

### Generic actions

```php
use Apn\AmiClient\Protocol\GenericAction;

Ami::default()->send(new GenericAction('QueueSummary', [
    'Queue' => 'support',
]));
```

### Completion strategies (high level)

- Default strategy is single-response completion.
- Some actions use multi-event completion (e.g., `QueueStatus`, `QueueSummary`, `PJSIPShowEndpoint(s)`).
- `Command` uses a follows-response strategy for multi-line output.

Action send notes:
- `send()` requires a READY connection and throws `InvalidConnectionStateException` otherwise.
- Backpressure can raise `BackpressureException` or `ActionSendFailedException` when buffers are full.

## 7) Events

```php
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Laravel\Ami;

Ami::onEvent('Hangup', function (AmiEvent $event): void {
    // handle a specific event
});

Ami::onAnyEvent(function (AmiEvent $event): void {
    // handle all events from all servers
});
```

Backpressure behavior:
- Event queue is fixed-capacity.
- When full, the oldest event is dropped.
- Drop counts are tracked in metrics (`ami_dropped_events_total`).

## 8) Non-Blocking Runtime Guarantees (NBRC)

Guaranteed by the library:
- `tick()` / `tickAll()` use non-blocking I/O with bounded selector waits.
- `tickAll()` multiplexes all servers with a single selector call.
- Timeout ranges are enforced (`0..250ms`). Negative values throw, oversize values are clamped with telemetry.
- No blocking DNS inside tick/reconnect/runtime paths when `enforce_ip_endpoints` is enabled.

Required from you:
- Use bounded cadence in worker loops (`10–50ms` typical).
- Never call blocking DNS, sleeps, or heavy I/O in event listeners.
- Keep any external work off the tick path (dispatch jobs or queues).

## 9) Operational Guidance

Recommended topology for Laravel:
- One dedicated `ami:listen` worker process.
- An event bridge (Redis pub/sub or a dedicated bus) feeding your application workers.

Logging fields you should expect:
- `server_key`
- `action_id`
- `queue_depth`

Health and reconnect behavior (high level):
- Backoff with jitter for reconnect attempts.
- Circuit breaker with `HALF_OPEN` probe rules to prevent reconnection storms.

## 10) Testing and Verification

Run the full suite:

```bash
vendor/bin/phpunit
```

Governance artifacts:
- `docs/ACTIVE-BATCH.md`
- `docs/deltas/`
- `docs/task-batches/`
- `docs/audit/`

> Common pitfalls:
> - Using hostnames with `enforce_ip_endpoints=true`.
> - Calling `tickAll(0)` in a tight loop without any other work or cadence.
> - Doing database or network calls directly inside `onEvent` callbacks.

## Doc-to-Contract Alignment Checklist

- NBRC non-blocking guarantees are summarized and respected.
- Timeout range and clamping semantics are documented.
- Worker cadence avoids hot-spin in Profile B (`ami:listen`).
- Endpoint policy and DNS constraints are explicit.
- Multi-server isolation and bounded queues are documented.
