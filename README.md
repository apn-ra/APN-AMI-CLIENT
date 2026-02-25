# apn/ami-client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/apn/ami-client.svg?style=flat-square)](https://packagist.org/packages/apn/ami-client)
![PHP Version](https://img.shields.io/badge/php-8.4%2B-8892bf.svg?style=flat-square)
![Laravel Version](https://img.shields.io/badge/laravel-12.x-ff2d20.svg?style=flat-square)
[![Total Downloads](https://img.shields.io/packagist/dt/apn/ami-client.svg?style=flat-square)](https://packagist.org/packages/apn/ami-client)
[![License](https://img.shields.io/packagist/l/apn/ami-client.svg?style=flat-square)](https://packagist.org/packages/apn/ami-client)
[![Build Status](https://img.shields.io/github/actions/workflow/status/apn/ami-client/tests.yml?branch=main&style=flat-square)](https://github.com/apn/ami-client/actions)
[![Coverage](https://img.shields.io/codecov/c/gh/apn/ami-client?style=flat-square)](https://codecov.io/gh/apn/ami-client)

A high-performance, non-blocking Asterisk Manager Interface (AMI) client designed for Laravel 12. Built for stability in 24/7 dialer environments and long-lived worker processes.

## Introduction

The `apn/ami-client` package provides a modern, dialer-grade replacement for legacy libraries like PAMI. It is architected from the ground up to support multi-server Asterisk clusters with a strictly non-blocking I/O model, ensuring your application remains responsive even under extreme event volumes.

Targeted at production environments requiring high reliability, it handles the complexities of action correlation, reconnection backoff, and memory safety automatically.

## Features

- **Multi-Server Support**: Orchestrate connections to multiple Asterisk nodes seamlessly.
- **Non-Blocking I/O**: Driven by a deterministic tick loop using `stream_select`.
- **Action Correlation**: Automatic `ActionID` generation and response mapping.
- **GenericAction Support**: Send any AMI action without needing specialized classes.
- **Event Subscription**: Flexible, non-blocking event handling model.
- **Backpressure Protection**: Bounded event queues and outbound buffers to prevent OOM.
- **TLS Support**: Secure connections to Asterisk manager interfaces.
- **Long-Lived Worker Safe**: Zero-leak architecture verified by 24h soak tests.

## Installation

Install the package via Composer:

```bash
composer require apn/ami-client
```

The package will automatically register its Service Provider and Facade in Laravel 12.

Publish the configuration file:

```bash
php artisan vendor:publish --tag=ami-config
```

## Quickstart

### 1. Configure your servers
In `config/ami-client.php`:

```php
'servers' => [
    'node_1' => [
        'host' => env('AMI_NODE1_HOST', '127.0.0.1'),
        'port' => 5038,
        'username' => 'admin',
        'secret' => 'amp111',
    ],
],
```

### 2. Send an Action
```php
use Apn\AmiClient\Laravel\Ami;
use Apn\AmiClient\Protocol\Ping;

// Simple ping to default server
Ami::default()->send(new Ping())->onComplete(function ($exception, $response) {
    if ($response?->isSuccess()) {
        // Handle success
    }
});
```

### 3. Originate a Call
```php
use Apn\AmiClient\Protocol\Originate;

Ami::server('node_1')->send(new Originate(
    channel: 'PJSIP/101',
    exten: '500',
    context: 'default',
    priority: 1
));
```

### 4. Listen for Events
```php
use Apn\AmiClient\Events\AmiEvent;

Ami::onEvent('Hangup', function (AmiEvent $event) {
    $channel = $event->getHeader('Channel');
    // Process event (must be non-blocking!)
});
```

## Worker Usage

For production stability, it is recommended to run a dedicated worker process for each Asterisk server.

```bash
php artisan ami:listen --server=node_1
```

This worker handles heartbeats, reconnections, and event ingestion. It automatically handles `SIGTERM` and `SIGINT` for graceful shutdowns, ensuring all pending data is flushed before exiting.

## Configuration

Key configuration options in `config/ami-client.php`:

- **servers**: Define multiple Asterisk nodes with host, port, credentials, and TLS settings.
- **options**:
    - `heartbeat_interval`: Seconds between Pings (default: 15).
    - `max_pending_actions`: Limit concurrent actions to prevent memory growth.
    - `event_queue_capacity`: Maximum number of events to buffer before dropping (default: 10,000).
    - `write_buffer_limit`: Maximum outbound byte-stream size.

## Architecture Overview

The package is strictly layered to ensure core logic remains framework-agnostic:

- **Transport**: Manages raw TCP/TLS streams using non-blocking I/O.
- **Protocol**: Handles AMI framing, parsing raw bytes into typed Message DTOs.
- **Correlation**: Tracks `ActionID`s to map incoming responses back to their original actions.
- **Cluster**: Orchestrates multiple `AmiClient` instances and manages routing.
- **Health**: Monitors connectivity via heartbeats and implements exponential backoff for reconnections.

## Production Recommendations

1. **Do Not Block in Listeners**: Event listeners execute within the main tick loop. Heavy processing or blocking I/O (like DB writes) must be dispatched to a Laravel Job.
2. **Monitor Reconnects**: Log and monitor connection status changes to detect network or Asterisk instability.
3. **Monitor Event Drops**: If the `dropped_events` counter increments, increase your queue capacity or optimize your listeners.
4. **Prefer Isolated Workers**: Use the `ami:listen` command with Supervisor to manage long-lived processes.
5. **Always Use Bounded Queues**: Ensure `event_queue_capacity` and `write_buffer_limit` are set to prevent Out-Of-Memory (OOM) errors during flood events.

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details.

---
Copyright (c) 2026 APN
