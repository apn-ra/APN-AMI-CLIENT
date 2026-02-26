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
2.  **Transport Layer:** Responsible for the physical connection (TCP/TLS) for a single server. It handles non-blocking socket I/O and byte buffering. **MUST NOT generate ActionIDs.**
3.  **Protocol Layer:** Implements AMI-specific framing. It parses raw bytes into `Message` objects and serializes `Action` objects.
4.  **Correlation Layer:** The "brain" of the request-response cycle. It generates **globally unique `ActionID`s** and maintains a registry of `PendingAction` objects. **ActionID generation belongs strictly here or in the Client layer.**
5.  **Health & Lifecycle Layer:** Manages connection states, heartbeats, and reconnection logic per server connection.
6.  **Event Ingestion Layer:** Distributes parsed events to registered listeners and subscription callbacks.
7.  **Laravel Integration Layer:** Provides a `ServiceProvider` to bootstrap the cluster manager using Laravel's configuration and logging stack.

### 5.1 Strong Node Isolation Invariants

To ensure "dialer-grade" stability, the system enforces strict node isolation. A failure or resource spike on one node MUST NOT impact others.

**Per-Server Isolated State:**
-   **Parser Buffer:** Each connection maintains its own byte buffer and framing state.
-   **Write Buffer:** Outbound data is queued per-server.
-   **Event Queue:** Inbound events are buffered in isolated, bounded queues.
-   **Correlation Registry:** Action-to-Response mapping is scoped per server.
-   **Reconnect State Machine:** Reconnection timers and backoff logic operate independently.

**Invariance Guarantees:**
-   **Parse Failure:** A protocol desync on Server A must not halt processing for Server B.
-   **Flood Isolation:** An event storm on Server A may cause event drops for Server A but must not delay dispatch for Server B.
-   **Correlation Safety:** Responses from Server A must never resolve pending actions for Server B.
-   **Memory Safety:** Large `Follows` responses on one node must be capped to prevent global OOM.

---

## 6. Non-Blocking Transport & I/O Architecture

To remain event-loop friendly, the transport MUST operate on a strictly non-blocking I/O model to prevent worker stalls during network congestion or Asterisk slow-responses.

### 6.1 Transport Interface & Stream Multiplexing

-   **Mechanism:** All I/O MUST be multiplexed using `stream_select()`.
-   **Execution Modes:**
    -   **Embedded Mode:** Each client instance manages its own socket and `tick()`.
    -   **Cluster Multiplexing Mode (Recommended for Dialers):** `AmiClientManager::tickAll()` gathers all active sockets and performs a **single `stream_select()`** call across all servers. This prevents unfair CPU usage and OS context switching.
-   **Tick Ownership:** The `AmiClientManager` (or a dedicated Worker) owns the main loop.
-   **`tick(int $timeoutMs)` logic:**
    1.  **I/O Multiplexing:** Perform `stream_select` for `READ` and `WRITE` readiness.
    2.  **Read & Parse:** Pull bytes into the Protocol Parser buffer (up to `max_bytes_read_per_tick`).
    3.  **Flush:** If `WRITE` ready, attempt to flush `WriteBuffer`.
    4.  **Correlation Sweep:** Process timeouts and expired actions.
    5.  **Event Dispatch:** Dispatch events from the internal queue (up to `max_events_per_tick`).
    6.  **Health Check:** Execute heartbeats and state transitions.

### 6.2 Per-Tick Fairness Budgets

To prevent a single "noisy" node from starving others, each tick enforces strict budgets:

-   `max_frames_per_tick`: Limit AMI frames parsed per connection per tick.
-   `max_events_per_tick`: Limit events dispatched to application listeners per tick.
-   `max_bytes_read_per_tick`: Limit raw bytes read from the socket per tick.
-   `max_connect_attempts_per_tick`: Cluster-level limit on concurrent reconnection attempts to mitigate "thundering herd" CPU spikes.

### 6.3 Outbound Write Buffer
-   **Design:** Each client maintains an internal `WriteBuffer` (byte-stream).
-   **Partial Writes:** The transport MUST handle partial writes. If `fwrite()` returns fewer bytes than requested, the remaining bytes MUST stay at the head of the `WriteBuffer` for the next `tick()`.
-   **Max Buffer Size:** Default `5MB`. If the buffer exceeds this limit, the client MUST throw a `BackpressureException` on subsequent `send()` attempts.
-   **Backpressure Behavior:** Applications must catch `BackpressureException` to implement their own retry or shedding logic.

### 6.4 Interface: `Apn\AmiClient\Contracts\TransportInterface`

The Transport layer is responsible **only** for the socket lifecycle and byte movement. It must remain stateless regarding AMI logic.

-   **`open(): void`**: Establishes the connection.
-   **`close(): void`**: Gracefully closes the connection.
-   **`read(): ?string`**: Non-blocking read of available bytes.
-   **`write(string $data): int`**: Non-blocking write. Handles partial writes via internal buffering.
-   **`getStream(): resource`**: Returns the underlying stream for `stream_select` multiplexing.

**Responsibility Separation:**
-   **Transport:** Socket handling, TLS, non-blocking I/O, byte buffering.
-   **Client/Correlation:** ActionID generation, Action serialization, Completion strategies.
-   **Transport MUST NOT generate ActionIDs or parse AMI frames.**

---

## 7. Action & Correlation Contract

### 7.1 ActionID Specification
To ensure per-server isolation and defensive validation, all ActionIDs must follow a strict contract:

-   **Format:** `{server_key}:{instance_id}:{sequence_id}` (e.g., `pbx01:6f2b:1001`).
-   **Max Length:** ≤ 64 characters.
-   **Generation:** MUST be generated by the `CorrelationManager` or `AmiClient`, never by the Transport layer.
-   **Uniqueness:** Must be unique within the lifetime of the client instance for that specific server.

### 7.2 Completion Strategy Contract
Every action (Typed or Generic) must define a `CompletionStrategy` that governs its lifecycle:

-   **`max_duration_ms`**: Mandatory timeout for the action.
-   **`max_messages`**: Safety cap on the number of messages a multi-response action can receive.
-   **`terminal_event_names[]`**: List of event names that signify completion (e.g., `QueueStatusComplete`).
-   **Termination Predicate**: Optional callback to inspect headers for completion (e.g., `EventList: Complete`).
-   **Timeout Exception**: Must throw `ActionTimeoutException` when `max_duration_ms` is exceeded.

**Canonical Handlers:**
-   **SingleResponse:** Completes on the first `Response: Success/Error`.
-   **MultiResponse (Event-based):** Completes when a terminal event is received (e.g., `QueueStatus` -> `QueueStatusComplete`).
-   **ListResponse (Header-based):** Completes when `EventList: Complete` is found in a response.

---

## 8. Generic Action Framework

To support arbitrary AMI actions and future-proof the client, a `GenericAction` framework is required.

### 8.1 GenericAction Design
-   **DTO Structure:** `GenericAction` is an immutable DTO that accepts an action name and arbitrary headers.
-   **No Hardcoding:** Allows sending any action supported by Asterisk without requiring a dedicated typed class.
-   **Default Policy:** The default completion policy is `SingleResponseStrategy`.
-   **Strategy Overrides:** Support overriding the `CompletionStrategy`.

---

## 9. Command Action & Follows Parsing

The `Command` action returns raw output in a format that breaks standard AMI key-value framing (`Response: Follows`).

### 9.1 Follows Handling
-   **Response Recognition:** Detect `Response: Follows` header.
-   **Termination Logic:** 
    -   Use sentinel-based termination (e.g., `--END COMMAND--`) as provided by Asterisk.
    -   The `FollowsResponseStrategy` must handle the state transition from KV-parsing to raw-line buffering.
-   **Memory Protection:**
    -   Enforce a `max_output_size` (default 1MB).
    -   If the limit is exceeded, discard the remainder and fail the action with a `ProtocolException`.
-   **Completion Strategy:** Implement `FollowsResponseStrategy` to buffer all output lines until the terminator is reached before resolving the `PendingAction`.

---

## 10. Event Ingestion & Subscription Model

A formal model is needed to handle unsolicited AMI events efficiently. The Core event system is internal and lightweight and MUST NOT depend on Laravel's event dispatcher.

### 10.1 Event Dispatch Pipeline
To ensure fairness and prevent CPU spikes, events follow a strictly governed pipeline:

1.  **Read:** Raw bytes are pulled from the transport (up to `max_bytes_read_per_tick`).
2.  **Parse:** Protocol layer frames bytes into `AmiEvent` objects (up to `max_frames_per_tick`).
3.  **Enqueue:** Events are placed into the **per-server** bounded event queue.
4.  **Dispatch:** Events are popped from the queue and passed to listeners (up to `max_events_per_tick`).

### 10.2 AmiEvent Model
-   **Object:** `AmiEvent` is an immutable normalized object.
-   **Required Fields:**
    -   `name`: The event name (e.g., `DeviceStateChange`).
    -   `headers`: Normalized array of event headers.
    -   `server_key`: The key of the server that emitted the event.
    -   `received_at`: Float timestamp (microseconds).
-   **Dynamic Discovery:** No per-event class requirement; all events are represented by `AmiEvent` by default.

### 10.3 Subscription API
-   **Global Listeners:** `onAnyEvent(callable $listener)` for catch-all processing.
-   **Targeted Listeners:** `onEvent(string $eventName, callable $listener)` for specific event types.
-   **Server Scoping:** Capability to subscribe to events on a per-server basis through the `AmiClient` instance.
-   **Filtering Mechanism:** Support for basic header-based filtering.
-   **Prioritization:** Implemented as *within-tick ordering*. High-priority events (e.g., `DialBegin`) are moved to the front of the per-tick dispatch batch, but they cannot starve the main loop or other servers.

---

## 11. Flood Control & Backpressure Rules

To maintain stability under high load, strict limits must be enforced on event processing.

### 11.1 Event Backpressure
-   **Bounded Queues:** Per-server event queue with a hard limit (default 10,000).
-   **Drop Policy (LIFO):** If the queue is full, discard the **oldest** events and increment a `dropped_events` counter.
-   **Dispatch Budget:** The `max_events_per_tick` limit ensures the application remains responsive to I/O even during massive event storms.
-   **Logging:** Every event drop must be logged as a warning with the current queue depth and server key.

---

## 12. Logging Contract

### 12.1 PSR-3 Dependency
The Core depends on `psr/log` only. If no logger is provided during construction, it MUST default to a `NullLogger` to prevent runtime errors.

### 12.2 Mandatory Log Fields
To ensure observability in multi-server environments, all logs emitted by the Core SHOULD include:
-   `server_key`: The identifier of the node.
-   `action_id`: If the log relates to a specific action.
-   `queue_depth`: Always present in structured output (normalized to `null` when not queue-related).

### 12.3 Security & Redaction
-   **Secrets:** `Secret`, `Password`, and sensitive AMI variables MUST be masked (e.g., `********`) before being passed to the logger.
-   **No Raw Frames:** Avoid logging entire raw protocol frames unless in `DEBUG` mode with sensitive data removed.

---

## 13. Multi-Server Management & Routing

### 13.1 `AmiClientManager` Contract
The `AmiClientManager` is the primary entry point for the application.

-   **`server(string $serverKey): AmiClientInterface`**: Returns a cached client for the specified server.
-   **`default(): AmiClientInterface`**: Returns the client for the default server.
-   **`tickAll(int $timeoutMs = 0): void`**: Iterates through all active server connections and executes their `tick()` logic.
-   **`routing(RoutingStrategyInterface $strategy): self`**: Fluent setter for dynamic routing.

### 13.2 Routing Strategies
-   **Explicit:** Target a specific node.
-   **Round-Robin:** Cycle through healthy nodes.
-   **Failover:** Use primary, fallback to secondary.
-   **Health-Aware:** Router queries the `HealthMonitor` to exclude `Disconnected` or `Degraded` nodes.

---

## 14. Action Strategy Roadmap

The client supports incremental expansion of typed actions while the `GenericAction` provides immediate support for everything else.

### 14.1 v1 Core (Minimal)
-   Login, Logoff, Ping, GenericAction.

### 14.2 v1.5 Common Dialer Extensions
-   Originate, Hangup, Redirect, SetVar, GetVar, Command (Follows).

### 14.3 v2 Extended Action Set
-   QueueStatus, Status/CoreShowChannels, QueueSummary.
-   QueueAdd/Remove/Pause.
-   PJSIPShowEndpoint, ExtensionState.

---

## 15. Protocol Parser & Safety

The parser is responsible for turning raw stream bytes into structured AMI messages. It must be resilient to protocol drift and malicious or malformed input.

### 15.1 Hardening Rules
-   **Delimiters:** Handle both standard `\r\n\r\n` and defensive `\n\n` as frame boundaries.
-   **Max Frame Size:** Configurable per-connection cap via `ClientOptions.max_frame_size` with a safe default of **1MB** and bounded range (**64KB..4MB**). Frames exceeding this cap must be discarded.
-   **Max Parser Buffer:** The cumulative buffer for a single connection must have a hard cap (e.g., 2MB) to prevent OOM.
-   **Desync Recovery:** If invalid protocol data is encountered:
    1.  Discard bytes until the next valid double-newline delimiter is found.
    2.  Increment a `protocol_desync_count`.
    3.  Reset the connection **only if** the desync count exceeds a threshold within a time window.
-   **Key Normalization:** All keys must be converted to lowercase (e.g., `ActionID` -> `actionid`).
-   **Duplicate Keys:** Must be treated as an array of values (e.g., for `Variable` headers).

---

## 16. Connection Lifecycle & Health

A robust state machine manages the lifecycle of each server node, ensuring deterministic behavior during failure and recovery.

### 16.1 State Machine States
-   **`DISCONNECTED`**: No active socket.
-   **`CONNECTING`**: TCP handshake in progress.
-   **`AUTHENTICATING`**: Socket open; waiting for Banner or sending `Login` action.
-   **`CONNECTED_HEALTHY`**: Successfully authenticated; heartbeats passing.
-   **`CONNECTED_DEGRADED`**: Socket open; authentication success, but heartbeats failing.
-   **`RECONNECTING`**: Waiting for backoff timer to expire before retrying.

### 16.2 Authentication & Event Flow
-   **Banner Handling:** The parser must handle the `Asterisk Call Manager/X.Y` banner as a protocol event.
-   **Login Timeout:** If authentication does not complete within `auth_timeout_ms`, the connection is reset.
-   **Event Ingestion:** Unsolicited events MUST NOT be dispatched to application listeners until the `CONNECTED_HEALTHY` state is reached (unless explicitly configured).
-   **Event Mask Policy:** The `Events` mask for the session must be set during the `Login` action or immediately after.

### 16.3 Reconnect Storm Protection
To prevent CPU spikes and server overload when multiple nodes fail simultaneously:
-   **Backoff:** Mandatory exponential backoff with a randomized jitter component.
-   **Cluster Throttling:** `max_connect_attempts_per_tick` limits how many nodes can transition from `DISCONNECTED` to `CONNECTING` in a single tick.
-   **Circuit Breaker:** After `max_reconnect_attempts`, the node is marked as `FATAL` and requires manual intervention or a hard reset.

### Production Readiness Remediation (Audit-Driven)

The following remediations are mandatory to satisfy 24/7 dialer-grade behavior and are tracked in `docs/tasks.md` under the `PR-*` IDs.

-   **Async connect state machine (Tasks: PR-P0-01, PR-P0-02, PR-P0-03):**
    -   Enforce explicit per-node states: `DISCONNECTED -> CONNECTING -> CONNECTED -> AUTHENTICATING -> READY`.
    -   `CONNECTING` uses `STREAM_CLIENT_ASYNC_CONNECT` only; no blocking `stream_socket_client` connect path is allowed inside `tick()`/`processTick()`.
    -   Promote to `CONNECTED` only after write-readiness and socket error checks confirm handshake completion.
-   **Connect timeout semantics redefinition (Task: PR-P0-04):**
    -   `connectTimeout` is the maximum allowed wall-clock duration in `CONNECTING`.
    -   Timeout expiration transitions to reconnect scheduling; it must never block a tick.
-   **Reconnect fairness (Task: PR-P0-05):**
    -   Use a round-robin reconnect cursor across eligible nodes so later nodes are not starved when `max_connect_attempts_per_tick` is capped.
    -   Cursor advancement is mandatory even when an attempted node fails.
-   **Listener exception isolation (Tasks: PR-P1-01, PR-P1-02):**
    -   Wrap each listener invocation in `try/catch` in both `AmiClient` and `AmiClientManager`.
    -   One listener failure must not prevent delivery to remaining listeners or servers.
-   **`send()` eligibility gate (Task: PR-P1-03):**
    -   Only allow send when node state is send-eligible (`READY`).
    -   Otherwise throw a typed exception containing at least `server_key` and current state.
-   **Observability contract for reconnect/connect failures (Task: PR-P1-04):**
    -   Standardize structured failure/retry logs with `server_key`, `host`, `port`, `attempt`, `backoff`, `next_retry_at`.
-   **`readTimeout` contract (Task: PR-P2-01):**
    -   Keep `readTimeout` as an idle-read observability and liveness threshold in the non-blocking loop (not a blocking socket timeout).
    -   On threshold breach, log and trigger health/reconnect policy consistently with config semantics.
-   **Per-node circuit breaker policy (Tasks: PR-P2-02, PR-P2-03):**
    -   Implement states `CLOSED`, `OPEN`, `HALF_OPEN` per node.
    -   Trip rule: open breaker after consecutive connect/auth failures reaches configured threshold.
    -   Cooldown: remain `OPEN` until cooldown expires.
    -   Probe: allow limited attempts in `HALF_OPEN`; close on success, reopen on failure.
    -   Log all state transitions with reason and counters.
-   **`queue_depth` log standardization (P3) (Task: PR-P3-01):**
    -   Normalize `queue_depth` field usage across event/backpressure/reconnect related logs.
    -   Enforce `queue_depth` presence (value or `null`) and include queue context in queue-related categories.
    -   Preserve compatibility with existing log structure while adding missing contexts.

--- 

## 17. Worker & Runtime Model

-   **Graceful Shutdown:** Catch `SIGTERM/SIGINT`, flush buffers, send `Logoff`.
-   **Non-Blocking:** No `sleep()` or blocking I/O allowed.
-   **Deterministic Tick:** Every tick performs I/O, parsing, correlation sweep, and health checks.


---

## 18. Laravel Integration Layer (Adapter)

The Laravel layer acts as a bridge between the framework and the Core client.

### 18.1 Responsibilities of `src/Laravel`
-   **Configuration:** Merge and publish `ami-client.php`. Map configuration arrays to `ServerConfig` and `ClientOptions` DTOs.
-   **Container Bindings:** Bind `AmiClientManager` as a singleton in the service container.
-   **Strategy Binding:** Bind the default `RoutingStrategyInterface` (e.g., `RoundRobin`).
-   **Logging Bridge:** Inject the Laravel/PSR logger into the manager.
-   **Facade:** Provide the `Ami` facade for convenient access.
-   **Artisan Command:** Provide the `ami:listen` command for Profile B runtime.
-   **Event Bridging:** (Optional/Opt-in) Optionally bridge AMI events into Laravel's native event system. This must be disabled by default due to performance costs in high-throughput environments.

### 18.2 Facade vs. Dependency Injection
While the `Ami` facade is provided for convenience, **Constructor Injection** of `AmiClientManager` is the preferred method for application services to ensure better testability and avoid tight coupling to static state.

### 18.3 Recommended Connection Topology

In a Laravel environment, the connection topology is critical for dialer-grade stability.

-   **Connection Explosion Risk:** Running `N` queue workers (e.g., Horizon) connecting to `M` Asterisk nodes creates `N × M` total AMI connections. This often leads to Asterisk manager exhaustion and high overhead.
-   **Recommended Pattern (Event Bridge):**
    -   Run a **single dedicated `ami:listen` process** (Profile B).
    -   This process maintains stable connections to all Asterisk nodes.
    -   Events are bridged to a low-latency transport (e.g., Redis `PUBLISH` or a dedicated Event Bus) for other workers to consume asynchronously.
-   **Direct Pattern (Advanced):** Only use direct `AmiClient` instances within short-lived workers or web requests if the process count is strictly bounded and the latency penalty of the AMI handshake is acceptable.

---

## 19. Testing Strategy

-   **Unit Coverage:** 100% for Parser, Correlation, and Routing logic.
-   **Integration:** Mock socket servers for multi-server scenarios.
-   **Flood Simulation:** 10x normal load to verify drop policies.
-   **Reconnect Storm:** Verify backoff/jitter stability.
-   **Parser Corruption:** Verify re-sync after garbage injection.
-   **Follows Parsing:** Specifically test `Command` output and terminators.
-   **Soak Test:** 24h period with zero memory growth.

---

## 20. Acceptance Criteria

1.  **Cluster Fairness:** Noisy servers must not starve others; verified via per-tick fairness budgets.
2.  **Strong Isolation:** A crash, buffer overflow, or protocol desync on Node A must have zero impact on Node B's memory or processing.
3.  **ActionID Integrity:** All ActionIDs must follow the `{server}:{instance}:{seq}` contract and be generated above the transport layer.
4.  **Completion Strategy Correctness:** Multi-message actions (e.g., `QueueStatus`) and Follows actions (e.g., `Command`) must capture all data and terminate correctly or throw `ActionTimeoutException`.
5.  **Reconnect Herd Mitigation:** Reconnection storms must be throttled at the cluster level and use jittered backoff.
6.  **Memory Stability:** Zero memory growth over 24h soak tests, even under flood and reconnection cycles.
7.  **Follows Parsing:** `Command` output must be correctly captured with enforced size limits and terminator detection.
8.  **Generic Support:** Must be able to send any AMI action using `GenericAction`.
9.  **Backpressure Safety:** Event drops and write buffer overflows must be handled gracefully without crashing the worker.

---

## Appendix: Production Readiness Deltas

### Delta Index

| Batch ID | Date | Summary | Linked Task ID Range |
| --- | --- | --- | --- |
| BATCH-PR-20260226-02 | 2026-02-26 | Parser/Correlation/Logging/Security Hardening | PR2-P1-01 â€¦ PR2-P2-05 |

### Delta 2026-02-26 (BATCH-PR-20260226-02): Parser/Correlation/Logging/Security Hardening

1) Parser: Configurable max frame size (P1) â€” Tasks: `PR2-P1-01`, `PR2-P1-02`
- Replace hard-coded 64KB `MAX_FRAME_SIZE` with a configurable value via `ClientOptions`.
- Set a safe default in the 1â€“4MB range and keep an upper bound to ensure bounded memory.
- Document how this interacts with `Follows` / large multi-part responses and existing strategy limits.
- Document failure behavior for over-limit frames: typed exception, connection behavior, and recovery guarantees.

2) Correlation: No synthetic success on missing response (P1) â€” Tasks: `PR2-P1-03`, `PR2-P1-04`
- If completion signals occur but the response is missing, default behavior must be typed failure (e.g., `ProtocolException`/`ConnectionLostException`) or remain pending until timeout.
- Synthetic responses are allowed ONLY for explicitly event-only strategies; document those strategies.
- Document invariants and how false positives are prevented.

3) Logging: `queue_depth` standardization (P2) â€” Task: `PR2-P2-01`
- Update logging schema guidance to normalize `queue_depth` to `null` OR require it in all queue-related logs.
- Specify which log categories MUST include `queue_depth` (enqueue/dequeue, drops, backpressure, timeouts, reconnect storms).

4) Security: Expand/configure secret redaction (P2) â€” Tasks: `PR2-P2-02`, `PR2-P2-03`
- Expand key list and add regex-based key matching (password/secret/token/auth/key/etc).
- Allow redaction policy injection/config via `ClientOptions` (`redaction_keys`, `redaction_key_patterns`) in addition to secure defaults.
- Document tests and safe defaults.

5) Correlation: Bound `ActionID` length (P2) â€” Tasks: `PR2-P2-04`, `PR2-P2-05`
- Enforce max `ActionID` length (e.g., 96â€“128 chars).
- If exceeded: truncate + stable hash suffix to preserve uniqueness (configured via `ClientOptions.max_action_id_length`).
- Document uniqueness guarantees and logging expectations.
