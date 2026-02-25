# Implementation Plan: Dialer-Optimized AMI Client (Laravel 12 Package) — Multi-Server Ready

This document outlines the implementation strategy for a high-performance, standalone Asterisk Manager Interface (AMI) client. The package is designed for stability in 24/7 dialer environments and long-lived worker processes, explicitly supporting **multiple Asterisk nodes / PBXs** simultaneously.

---

## 1. Package Identity & Structure

### Package Details
- **Composer Name:** `apn/ami-client`
- **PSR-4 Namespace:** `Apn\AmiClient\`
- **Minimum PHP Version:** 8.4+
- **Framework Support:** Laravel 12.x (Adapter Layer)

### Folder Layout
```text
src/
├── Core/               # High-level Client and Domain Contracts
├── Cluster/            # Multi-server management, Routing, and Registry
├── Protocol/           # Parser, Message DTOs, Framing logic
├── Transport/          # Socket/Stream management, TLS handling
├── Correlation/        # ActionID registry and mapping logic
├── Events/             # Internal event system and listener interfaces
├── Exceptions/         # Domain-specific exception hierarchy
├── Health/             # State tracking, heartbeats, and lifecycle
├── Laravel/            # ServiceProvider, Facades, Laravel-specific Glue
config/                 # Default package configuration
tests/
├── Unit/               # Stateless component tests
├── Integration/        # Socket simulation and protocol tests (Multi-server focus)
└── Performance/        # Benchmarking and memory leak detection
```

### Component Classification
- **Pure PHP (Framework-Agnostic):** `Core`, `Cluster`, `Protocol`, `Transport`, `Correlation`, `Events`, `Exceptions`, `Health`. These must not import any `Illuminate\*` namespaces.
- **Laravel Adapter Layer:** `Laravel`, `config`. This layer handles container bindings and PSR-3 logger integration.

---

## 2. Dependency & Boundary Contract

The Core layers of the package MUST remain strictly framework-agnostic.

### 2.1 Core Layer Dependencies

The Core Layer **MAY** depend on:
-   `psr/log`: For structured logging interfaces.
-   `psr/event-dispatcher`: (Optional) If used for internal event distribution.
-   Pure PHP Standard Library (SPL).

The Core Layer **MUST NOT** depend on:
-   `Illuminate\*`: No Laravel components.
-   `Symfony\Console`: CLI logic belongs in the adapter layer.
-   Laravel helpers: No `env()`, `config()`, `collect()`, etc.
-   Facades: No static access to framework services.

> **Rule:** The Core must be installable and usable in plain PHP without Laravel.

---

## 3. Runtime Profiles

The client core does **NOT** assume ownership of the main loop. Runtime ownership is external, allowing integration into various process models.

### Profile A — Pure PHP Worker
-   Manual instantiation of `AmiClientManager`.
-   Manual implementation of the `tick()` loop.
-   Lifecycle managed by external tools like Supervisor or Systemd.

### Profile B — Laravel Artisan Worker
-   Uses the `ami:listen` Artisan command.
-   `AmiClientManager` is bound via the ServiceProvider.
-   Handles OS signals (`SIGTERM`, `SIGINT`) and clean shutdown via the framework's console kernel.

### Profile C — Embedded Tick Mode
-   The application calls `tickAll()` inside its own existing event or worker loop.
-   No dedicated AMI worker process is required.

> **Constraint:** Runtime ownership is external. The core provides the mechanism to "tick," but the environment provides the "loop."

---

## 4. Construction & Lifecycle

### 4.1 AmiClientManager Construction
The `AmiClientManager` is the root coordinator. In Laravel, it is a singleton, but in pure PHP, it can be instantiated manually.

**Constructor Dependencies:**
-   `ServerRegistry`: Defines available nodes.
-   `ClientOptions`: Global configuration DTO.
-   `LoggerInterface`: PSR-3 compliant logger.
-   `ClockInterface`: (Optional, recommended) For testing time-based logic.

### 4.2 Connection Strategy
-   **Lazy-open (Recommended):** Connections are established only when the first action is sent or when explicitly requested.
-   **Eager-open:** All configured servers are connected during initialization via `connectAll()`.

---

## 5. Core Architecture Overview

The package follows a layered architectural approach to ensure separation of concerns and testability, now with a focus on multi-server orchestration.

1.  **Cluster Layer:** Manages multiple client instances. It handles routing actions to specific servers and provides a centralized access point (`AmiClientManager`).
2.  **Transport Layer:** Responsible for the physical connection (TCP/TLS) for a single server. It handles non-blocking socket I/O.
3.  **Protocol Layer:** Implements AMI-specific framing. It parses raw bytes into `Message` objects and serializes `Action` objects.
4.  **Correlation Layer:** The "brain" of the request-response cycle. It generates **globally unique `ActionID`s** (server-aware) and maintains a registry of `PendingAction` objects.
5.  **Health & Lifecycle Layer:** Manages connection states, heartbeats, and reconnection logic per server connection.
6.  **Event Ingestion Layer:** Distributes parsed events to registered listeners and subscription callbacks.
7.  **Laravel Integration Layer:** Provides a `ServiceProvider` to bootstrap the cluster manager using Laravel's configuration and logging stack.

---

## 6. Non-Blocking Transport & I/O Architecture

To remain event-loop friendly, the transport MUST operate on a strictly non-blocking I/O model to prevent worker stalls during network congestion or Asterisk slow-responses.

### 6.1 Transport Interface & Stream Multiplexing
-   **Mechanism:** All I/O MUST be multiplexed using `stream_select()`.
-   **Tick Ownership:** The `AmiClientManager` (or a dedicated Worker) owns the main loop, calling `tick()` on individual client instances.
-   **`tick(int $timeoutMs)` logic:**
    -   Perform `stream_select` on the socket for both `READ` and `WRITE` readiness.
    -   If `READ` ready: Pull bytes into the Protocol Parser buffer.
    -   If `WRITE` ready and `WriteBuffer` is not empty: Attempt to flush pending data.
    -   Process the correlation registry (timeouts).
    -   Dispatch events from the event queue.
    -   Execute health checks.

### 6.2 Outbound Write Buffer
-   **Design:** Each client maintains an internal `WriteBuffer` (byte-stream).
-   **Partial Writes:** The transport MUST handle partial writes. If `fwrite()` returns fewer bytes than requested, the remaining bytes MUST stay at the head of the `WriteBuffer` for the next `tick()`.
-   **Max Buffer Size:** Default `5MB`. If the buffer exceeds this limit, the client MUST throw a `BackpressureException` on subsequent `send()` attempts.
-   **Backpressure Behavior:** Applications must catch `BackpressureException` to implement their own retry or shedding logic.

### 6.3 Interface: `Apn\AmiClient\Contracts\TransportInterface`

-   **`open(): void`**: Establishes the connection. Throws `ConnectionException` on failure.
-   **`close(): void`**: Gracefully closes the connection.
-   **`send(Action $action): PendingAction`**: Serializes and queues an action for transmission. Returns a handle for tracking the response.
-   **`tick(int $timeoutMs = 0): void`**: The main driver for a single connection.

---

## 7. Generic Action Framework

To support arbitrary AMI actions and future-proof the client, a `GenericAction` framework is required.

### 7.1 GenericAction Design
-   **DTO Structure:** `GenericAction` is an immutable DTO that accepts an action name and arbitrary headers.
-   **No Hardcoding:** Allows sending any action supported by Asterisk without requiring a dedicated typed class.
-   **Default Policy:** The default completion policy is `SingleResponseStrategy`.
-   **Strategy Overrides:** Support overriding the `CompletionStrategy` for:
    -   Multi-message completion (e.g., `QueueStatus`).
    -   Async event completion (e.g., `Originate`).
    -   Follows responses (e.g., `Command`).

---

## 8. Command Action & Follows Parsing

The `Command` action returns raw output in a format that breaks standard AMI key-value framing.

### 8.1 Follows Handling
-   **Response Recognition:** Detect `Response: Follows` header.
-   **Termination Logic:** Use sentinel-based termination (e.g., `--END COMMAND--`) as provided by Asterisk for specific commands.
-   **Memory Protection:**
    -   Enforce a `Max Output Size` (e.g., 1MB) for follows responses.
    -   If the limit is exceeded, discard the remainder and fail the action with a `ProtocolException`.
-   **Completion Strategy:** Implement `FollowsResponseStrategy` to buffer all output lines until the terminator is reached before resolving the `PendingAction`.

---

## 9. Event Ingestion & Subscription Model

A formal model is needed to handle unsolicited AMI events efficiently. The Core event system is internal and lightweight and MUST NOT depend on Laravel's event dispatcher.

### 9.1 AmiEvent Model
-   **Object:** `AmiEvent` is an immutable normalized object.
-   **Required Fields:**
    -   `name`: The event name (e.g., `DeviceStateChange`).
    -   `headers`: Normalized array of event headers.
    -   `server_key`: The key of the server that emitted the event.
    -   `received_at`: Float timestamp (microseconds).
-   **Dynamic Discovery:** No per-event class requirement; all events are represented by `AmiEvent` by default.

### 9.2 Subscription API
-   **Global Listeners:** `onAnyEvent(callable $listener)` for catch-all processing.
-   **Targeted Listeners:** `onEvent(string $eventName, callable $listener)` for specific event types.
-   **Server Scoping:** Capability to subscribe to events on a per-server basis through the `AmiClient` instance.
-   **Filtering Mechanism:** Support for basic header-based filtering (e.g., only `Hangup` events for `Channel: PJSIP/100`).
-   **Prioritization Hooks:** Optional hooks for high-priority event processing (e.g., `DialBegin` handled before `VarSet`).

---

## 10. Flood Control & Backpressure Rules

To maintain stability under high load, strict limits must be enforced on event processing.

### 10.1 Event Backpressure
-   **Bounded Queues:** Per-server event queue with a hard limit (default 10,000).
-   **Drop Policy (FIFO):** If the queue is full, discard the **oldest** events and increment a `dropped_events` counter.
-   **Filtering at Ingestion:** Optional ability to discard unwanted event types before they enter the queue to save memory.
-   **Logging:** Every event drop must be logged as a warning with the current queue depth and server key.

---

## 11. Logging Contract

### 11.1 PSR-3 Dependency
The Core depends on `psr/log` only. If no logger is provided during construction, it MUST default to a `NullLogger` to prevent runtime errors.

### 11.2 Mandatory Log Fields
To ensure observability in multi-server environments, all logs emitted by the Core SHOULD include:
-   `server_key`: The identifier of the node.
-   `action_id`: If the log relates to a specific action.
-   `queue_depth`: If the log relates to buffer/queue states.

### 11.3 Security & Redaction
-   **Secrets:** `Secret`, `Password`, and sensitive AMI variables MUST be masked (e.g., `********`) before being passed to the logger.
-   **No Raw Frames:** Avoid logging entire raw protocol frames unless in `DEBUG` mode with sensitive data removed.

---

## 12. Multi-Server Management & Routing

### 12.1 `AmiClientManager` Contract
The `AmiClientManager` is the primary entry point for the application.

-   **`server(string $serverKey): AmiClientInterface`**: Returns a cached client for the specified server.
-   **`default(): AmiClientInterface`**: Returns the client for the default server.
-   **`tickAll(int $timeoutMs = 0): void`**: Iterates through all active server connections and executes their `tick()` logic.
-   **`routing(RoutingStrategyInterface $strategy): self`**: Fluent setter for dynamic routing.

### 12.2 Routing Strategies
-   **Explicit:** Target a specific node.
-   **Round-Robin:** Cycle through healthy nodes.
-   **Failover:** Use primary, fallback to secondary.
-   **Health-Aware:** Router queries the `HealthMonitor` to exclude `Disconnected` or `Degraded` nodes.

---

## 13. Action Strategy Roadmap

The client supports incremental expansion of typed actions while the `GenericAction` provides immediate support for everything else.

### v1 Core (Minimal)
-   Login, Logoff, Ping, GenericAction.

### v1.5 Common Dialer Extensions
-   Originate, Hangup, Redirect, SetVar, GetVar, Command (Follows).

### v2 Extended Action Set
-   QueueStatus, Status/CoreShowChannels, QueueSummary.
-   QueueAdd/Remove/Pause.
-   PJSIPShowEndpoint, ExtensionState.

---

## 14. Protocol Parser & Safety

-   **Framing:** Detect `\r\n\r\n` delimiter.
-   **Key Normalization:** Keys converted to lowercase.
-   **Duplicate Keys:** Stored as arrays.
-   **Max Frame Size:** Hard limit (64KB) for individual AMI frames.
-   **Desync Recovery:** On invalid data, discard until next `\r\n\r\n`.
-   **No Unbounded Memory Growth:** Strict upper bound on parser buffer.

---

## 15. Connection Lifecycle & Health

-   **State Machine:** DISCONNECTED, CONNECTING, AUTHENTICATING, CONNECTED_HEALTHY, CONNECTED_DEGRADED, RECONNECTING.
-   **Heartbeats:** AMI `Ping` every 15s.
-   **Reconnection:** Exponential backoff with jitter (100ms min, 30s max cap).
-   **Circuit Breaker:** Mark as fatal after repeated failures.

---

## 16. Worker & Runtime Model

-   **Graceful Shutdown:** Catch `SIGTERM/SIGINT`, flush buffers, send `Logoff`.
-   **Non-Blocking:** No `sleep()` or blocking I/O allowed.
-   **Deterministic Tick:** Every tick performs I/O, parsing, correlation sweep, and health checks.


---

## 17. Laravel Integration Layer (Adapter)

The Laravel layer acts as a bridge between the framework and the Core client.

### 17.1 Responsibilities of `src/Laravel`
-   **Configuration:** Merge and publish `ami-client.php`. Map configuration arrays to `ServerConfig` and `ClientOptions` DTOs.
-   **Container Bindings:** Bind `AmiClientManager` as a singleton in the service container.
-   **Strategy Binding:** Bind the default `RoutingStrategyInterface` (e.g., `RoundRobin`).
-   **Logging Bridge:** Inject the Laravel/PSR logger into the manager.
-   **Facade:** Provide the `Ami` facade for convenient access.
-   **Artisan Command:** Provide the `ami:listen` command for Profile B runtime.
-   **Event Bridging:** (Optional/Opt-in) Optionally bridge AMI events into Laravel's native event system. This must be disabled by default due to performance costs in high-throughput environments.

### 17.2 Facade vs. Dependency Injection
While the `Ami` facade is provided for convenience, **Constructor Injection** of `AmiClientManager` is the preferred method for application services to ensure better testability and avoid tight coupling to static state.

### 17.3 Recommended Connection Topology
In a Laravel environment, each PHP process (e.g., each Horizon worker) creates its own AMI connections.

-   **Risk:** Running 50 Queue workers with 10 Asterisk nodes results in 500 AMI connections, which may overwhelm the Asterisk manager interface.
-   **Recommended Pattern:** 
    -   Use a dedicated `ami:listen` process to own the AMI connections.
    -   Bridge critical events to Redis, Database, or Laravel events for other workers to consume.
-   **Direct Pattern:** Only use direct AMI connections in workers if the number of workers is low and strictly controlled.

---

## 18. Testing Strategy

-   **Unit Coverage:** 100% for Parser, Correlation, and Routing logic.
-   **Integration:** Mock socket servers for multi-server scenarios.
-   **Flood Simulation:** 10x normal load to verify drop policies.
-   **Reconnect Storm:** Verify backoff/jitter stability.
-   **Parser Corruption:** Verify re-sync after garbage injection.
-   **Follows Parsing:** Specifically test `Command` output and terminators.
-   **Soak Test:** 24h period with zero memory growth.

---

## 19. Acceptance Criteria

1.  **Generic Support:** Must be able to send any AMI action using `GenericAction`.
2.  **Event Handling:** Must support subscribing to any AMI event by name.
3.  **Memory Stability:** Zero leakage over 24h execution.
4.  **Node Isolation:** Failure of one node must not impact others.
5.  **Backpressure Safety:** Event drops and write buffer overflows must be handled gracefully without crashing the worker.
6.  **Follows Support:** `Command` output must be correctly captured and terminated.
