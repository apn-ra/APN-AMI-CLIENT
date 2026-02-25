# APN AMI Client: Production Engineering Guidelines

This document defines the mandatory engineering standards for the APN AMI Client modernization project. All code, refactors, and architectural decisions must strictly adhere to these rules.

## 1. Architectural Principles

*   **Layered Architecture Enforcement:** The system must be strictly layered. Higher layers may depend on lower layers, but lower layers must never depend on higher layers.
*   **Framework-Agnostic Core:** The core logic (Protocol, Transport, Correlation, Parser) must be entirely framework-agnostic.
*   **No Illuminate Imports in Core:** Use of any `Illuminate\*` namespaces (Laravel) is strictly forbidden within the core directory. Laravel integration must reside exclusively in the `Adapter` or `Bridge` layers.
*   **Strict Component Separation:** The following concerns must be decoupled via interfaces:
    *   **Transport:** Low-level socket handling and stream management.
    *   **Protocol:** AMI frame framing, parsing, and serialization.
    *   **Correlation:** Action-to-Response mapping and lifecycle management.
    *   **Cluster:** Multi-server management and routing logic.
    *   **Health:** Connectivity monitoring and circuit breaker state.
    *   **Laravel Adapter:** Facades, Service Providers, and Configuration.
*   **No Cross-Layer Leakage:** Internal implementation details of one layer (e.g., raw resource handles) must never be exposed to other layers.

## 2. Non-Blocking I/O Rules

*   **No Blocking Socket Reads:** All socket operations must use non-blocking mode. `stream_set_blocking($resource, false)` is mandatory.
*   **No Sleep-Based Polling:** Use of `sleep()`, `usleep()`, or `nanosleep()` within the worker loop is strictly prohibited.
*   **Stream Select Ownership:** The event loop or a dedicated `Reactor` component must own the `stream_select` call. No individual component may perform its own select calls.
*   **Partial Write Handling:** Every write operation must account for partial writes. Outbound data must be buffered if the socket is not ready for the full payload.
*   **Explicit Outbound Buffering:** Outbound messages must be queued in an internal buffer per connection.
*   **Maximum Buffer Size Enforcement:** Outbound buffers must have a hard-coded maximum size. If the limit is reached, the connection must be dropped to prevent OOM.
*   **Deterministic Tick Loop:** The main loop must execute in deterministic "ticks." Every tick must perform:
    1.  I/O Multiplexing (Read/Write).
    2.  Protocol Parsing.
    3.  Correlation Timeout Sweeps.
    4.  Health Checks.

## 3. Long-Lived Worker Safety

*   **Memory Leak Prevention:** All objects created within the tick loop must be short-lived or explicitly managed.
*   **Object Lifecycle Discipline:** Use of `unset()` on large buffers and closing resources immediately after use is mandatory.
*   **Explicit Cleanup Rules:** Every component must implement a `terminate()` or `cleanup()` method to release resources, listeners, and timers.
*   **No Static Mutable State:** Static variables for state storage are strictly forbidden. Use dependency injection and instance-based state.
*   **No Global Registries:** Use of global or singleton registries for connection management is prohibited.
*   **Bounded Queues Only:** All internal queues (Event, Action, Log) must have a fixed capacity.
*   **Periodic Timeout Sweeps:** The Correlation layer must sweep for expired actions every tick or at a fixed sub-second interval.
*   **Graceful Shutdown:** Workers must catch `SIGTERM` and `SIGINT`, stop accepting new actions, flush outbound buffers, and close connections cleanly before exiting.

## 4. Correlation & Action Rules

*   **Mandatory ActionID Format:** All ActionIDs must follow the format: `{server_key}:{instance_id}:{sequence_id}`.
*   **Per-Server Scoping:** Correlation registries must be scoped per server connection. Actions sent to Server A must never be resolvable by responses from Server B.
*   **Completion Strategy Interface:** Every Action must define a `CompletionStrategy` (e.g., `SingleResponse`, `MultiResponse`, `FollowsResponse`).
*   **Timeout Behavior:** Actions must have a mandatory timeout. Upon timeout, the pending entry must be purged and a `TimeoutException` must be propagated.
*   **Disconnect Failure Semantics:** If a connection is lost, all pending actions for that server must be immediately failed with a `ConnectionLostException`.
*   **PendingAction Lifecycle:** An action is "Pending" from the moment it is queued for writing until a final response is received, a timeout occurs, or the connection is lost.
*   **No Orphaned Entries:** The correlation engine must guarantee that every entry is eventually removed.

## 5. Multi-Server & Routing Rules

*   **Node Isolation:** A failure in one Asterisk node or its connection must not impact the stability or performance of connections to other nodes.
*   **Routing Strategy Interface:** All routing (Round-Robin, Failover, Sticky) must implement a `RoutingStrategy` interface.
*   **No Routing to Unhealthy Nodes:** Nodes in a `Disconnected` or `Degraded` state must be automatically excluded from routing.
*   **Circuit Breaker Rules:** Each server connection must have an independent circuit breaker.
*   **Health-Aware Selection:** The router must query the `HealthMonitor` before dispatching any action.
*   **No Cross-Node Shared State:** The client core must not share state (like ActionIDs or event history) across different server instances.

## 6. Parser & Protocol Safety

*   **Max Frame Size:** A hard limit (e.g., 64KB) must be enforced for individual AMI frames. Frames exceeding this must be discarded.
*   **Duplicate Key Handling:** If a frame contains duplicate keys, the parser must treat them as an array or log a protocol error, depending on the key.
*   **Follows Parsing Rules:** The parser must explicitly handle `Follows` and multi-line responses (e.g., for `Command` or `ConfigShow`).
*   **Desync Recovery:** If the parser encounters invalid protocol data, it must discard bytes until the next double-newline (`\r\n\r\n`) sequence.
*   **No Unbounded Memory Growth:** The parser buffer must have a strict upper bound.
*   **Strict Normalization:** All keys must be normalized (e.g., lowercase) during parsing.
*   **Explicit Error Strategy:** Protocol-level errors (e.g., truncated frames) must trigger a `ProtocolException` and connection reset if recovery is impossible.

## 7. Reconnection & Health Rules

*   **Backoff with Jitter:** Reconnection attempts must use exponential backoff with a randomized jitter component.
*   **Max Backoff Cap:** The reconnection delay must never exceed a configurable maximum (e.g., 30 seconds).
*   **Circuit Breaker Threshold:** After a fixed number of consecutive failures, the connection must enter `OPEN` state and stop attempting immediate reconnects.
*   **Heartbeat Interval:** A `Ping` action must be sent at regular intervals (e.g., every 15s) to verify connection viability.
*   **Degraded State Escalation:** If heartbeats fail but the socket is open, the connection must be marked `DEGRADED` and eventually reset.
*   **Deterministic Reconnect Logic:** Reconnection must be handled by a dedicated `ConnectionManager`, never by the parser or transport layers.
*   **No Infinite Tight Loops:** A minimum delay of 100ms must exist between any two reconnection attempts.

## 8. Flood & Backpressure Policy

*   **Per-Server Event Queue Limits:** Incoming events must be queued with a hard limit.
*   **Drop Policies:** When the event queue is full, the system must discard the oldest events (LIFO) and increment a `dropped_events` counter.
*   **Pending Action Limits:** A maximum number of concurrent pending actions per server must be enforced.
*   **Outbound Queue Limits:** The outbound write buffer must have a byte-count limit.
*   **OOM Protection:** If memory usage exceeds a defined threshold, the client must trigger an emergency shutdown.
*   **Logging of Drop Metrics:** Every instance of dropped events or rejected actions must be logged as a warning with the current queue depth.

## 9. Observability & Logging

*   **Structured Logging:** All logs must be emitted in JSON format with consistent fields.
*   **Required Fields:** `server_key`, `action_id` (where applicable), `worker_pid`, and `timestamp_ms` are mandatory for all logs.
*   **Secret Redaction:** `Secret`, `Password`, and sensitive `Variable` values must be masked (e.g., `********`) before logging.
*   **Metric Naming:** Use Prometheus-compatible naming (e.g., `ami_action_latency_ms_bucket`).
*   **Per-Server Labeling:** All metrics must be labeled with `server_key` and `server_host`.
*   **Latency Histograms:** Action execution time must be tracked via histograms, not just averages.
*   **Drop Counters:** Counters for dropped events and rejected actions are mandatory.

## 10. Testing Requirements

*   **Unit Coverage:** 100% coverage required for Parser, Correlation, and Routing logic.
*   **Integration Simulations:** Tests must use mock socket servers to simulate Asterisk behavior.
*   **Flood Simulation:** Verify system stability and drop policy under 10x normal load.
*   **Reconnect Storm:** Simulate simultaneous disconnect of all nodes and verify backoff/jitter.
*   **Parser Corruption:** Inject garbage bytes into the stream and verify recovery.
*   **24h Soak Test:** Zero memory growth must be verified over a 24-hour period with active traffic.
*   **Memory Stability:** Use `memory_get_usage()` in test assertions to ensure zero leakage after object destruction.

## 11. Code Quality & Style Rules

*   **Strict Typing:** `declare(strict_types=1);` is mandatory in every file.
*   **No Mixed Types:** Use of `mixed` type is prohibited unless interacting with external non-typed libraries.
*   **No Dynamic Properties:** All class properties must be explicitly declared.
*   **Explicit Return Types:** All methods and closures must have an explicit return type.
*   **No Hidden Side Effects:** Constructors must not perform I/O or complex logic.
*   **Immutable DTOs:** All Action and Event objects must be immutable (readonly classes/properties).
*   **Explicit Exception Hierarchy:** Use specific exceptions (e.g., `AmiTimeoutException`) rather than generic `\Exception`.
*   **No Silent Catch:** Catching an exception without logging or handling it is strictly forbidden.

## 12. What Is Strictly Forbidden

*   **Blocking I/O:** Any call that blocks the main loop.
*   **Unbounded Queues:** Any collection that can grow without limit.
*   **Global Mutable State:** Any state stored in static properties or globals.
*   **Silent Error Swallowing:** `try { ... } catch (\Throwable $e) {}` without action.
*   **Logging Secrets:** Including passwords or AMI secrets in log files.
*   **Hardcoded Credentials:** Storing credentials anywhere except environment variables or secure config.
*   **Implicit Routing:** Sending an action without a deterministic route.
*   **Implicit Health Overrides:** Manually forcing a connection to "Healthy" when health checks are failing.
