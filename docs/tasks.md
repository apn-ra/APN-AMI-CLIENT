# APN AMI Client: Implementation Task Checklist

## 1. Package Identity & Structure
1.1 [x] Define Composer name `apn/ami-client` and PSR-4 namespace `Apn\AmiClient\`.
1.2 [x] Set minimum PHP version 8.4+ in `composer.json`.
1.3 [x] Establish directory structure (`src/Core`, `src/Cluster`, `src/Protocol`, etc.) as per Section 1.

## 2. Dependency & Boundary Contract
2.1 [x] Ensure Core layers remain strictly framework-agnostic (no `Illuminate\*` imports).
2.2 [x] Integrate `psr/log` for structured logging.
2.3 [x] Audit Core directory to ensure zero dependencies on `Symfony\Console` or Laravel helpers.

## 3. Runtime Profiles
3.1 [x] Implement Profile A: Manual instantiation of `AmiClientManager` and manual `tick()` loop.
3.2 [x] Implement Profile B: Laravel Artisan Worker via `ami:listen` command.
3.3 [x] Implement Profile C: Embedded Tick Mode (external event loop integration).
3.4 [x] Ensure runtime ownership is external (core provides the mechanism, environment provides the loop).

## 4. Construction & Lifecycle
4.1 [ ] Implement `AmiClientManager` construction with `ServerRegistry`, `ClientOptions`, and `LoggerInterface`.
4.2 [ ] Support Lazy-open connection strategy (connect only when first action is sent).
4.3 [ ] Support Eager-open connection strategy (connect all configured servers via `connectAll()`).

## 5. Core Architecture Overview
5.1 [x] Implement Cluster Layer: Orchestrate multiple client instances.
5.2 [x] Implement Transport Layer: Non-blocking socket I/O for single servers.
5.3 [x] Implement Protocol Layer: AMI framing and Message/Action serialization.
5.4 [x] Implement Correlation Layer: Globally unique ActionIDs and PendingAction registry.
5.5 [x] Implement Health & Lifecycle Layer: State tracking and heartbeats.
5.6 [x] Implement Event Ingestion Layer: Distribute parsed events to listeners.
5.7 [x] Implement Laravel Integration Layer: ServiceProvider and framework glue.

## 6. Non-Blocking Transport & I/O Architecture
6.1 [x] Multiplex I/O using `stream_select()` for all connections.
6.2 [x] Implement `tick(int $timeoutMs)` logic to drive I/O and protocol parsing.
6.3 [x] Implement outbound `WriteBuffer` with partial write handling.
6.4 [x] Enforce `5MB` max buffer size and throw `BackpressureException` on overflow.
6.5 [x] Define and implement `TransportInterface` (`open()`, `close()`, `send()`, `tick()`).

## 7. Generic Action Framework
7.1 [x] Implement immutable `GenericAction` DTO for arbitrary AMI actions.
7.2 [x] Support `CompletionStrategyInterface` with `SingleResponseStrategy` as default.
7.3 [ ] Support overriding strategies for multi-message or async event completion.

## 8. Command Action & Follows Parsing
8.1 [x] Implement `Response: Follows` recognition logic in the parser.
8.2 [x] Implement sentinel-based termination (`--END COMMAND--`) for `Command` output.
8.3 [x] Enforce memory protection for follows responses (Max Output Size limit).
8.4 [x] Implement `FollowsResponseStrategy` to resolve after termination.

## 9. Event Ingestion & Subscription Model
9.1 [x] Implement immutable `AmiEvent` object with normalized fields and `server_key`.
9.2 [x] Implement Subscription API: `onAnyEvent()` and `onEvent(string $eventName)`.
9.3 [x] Support server-scoped event subscriptions via `AmiClient` instances.
9.4 [x] Implement header-based filtering mechanism for events.

## 10. Flood Control & Backpressure Rules
10.1 [x] Implement bounded per-server event queues (default 10,000).
10.2 [x] Implement FIFO drop policy for event queues when full.
10.3 [x] Support event type filtering at ingestion to save memory.
10.4 [x] Log warning metrics for every event drop with current queue depth and server key.

## 11. Logging Contract
11.1 [x] Depend strictly on `psr/log` and default to `NullLogger`.
11.2 [x] Include mandatory fields (`server_key`, `action_id`, `queue_depth`) in all logs.
11.3 [x] Implement secret redaction for `Secret`, `Password`, and sensitive AMI variables.
11.4 [x] Avoid logging raw frames unless in `DEBUG` mode with sensitive data removed.

## 12. Multi-Server Management & Routing
12.1 [x] Implement `AmiClientManager` methods: `server()`, `default()`, `tickAll()`, and `routing()`.
12.2 [x] Implement `RoutingStrategyInterface` for node selection logic.
12.3 [x] Implement strategies: `Explicit`, `Round-Robin`, `Failover`, and `Health-Aware`.

## 13. Action Strategy Roadmap
13.1 [x] v1 Core (Minimal): Implement Login, Logoff, Ping, GenericAction.
13.2 [ ] v1.5 Common Dialer Extensions:
    - [ ] Originate
    - [ ] Hangup
    - [ ] Redirect
    - [ ] SetVar
    - [ ] GetVar
    - [ ] Command (Follows)
13.3 [ ] v2 Extended Action Set (QueueStatus, PJSIPShowEndpoint, etc.)

## 14. Protocol Parser & Safety
14.1 [x] Implement framing detection using `\r\n\r\n` delimiter.
14.2 [x] Implement key normalization (lowercase) and duplicate key handling (arrays).
14.3 [x] Enforce 64KB hard limit for individual AMI frames.
14.4 [x] Implement desync recovery (discard bytes until next `\r\n\r\n`).
14.5 [x] Enforce strict upper bound on parser buffer memory growth.

## 15. Connection Lifecycle & Health
15.1 [x] Implement per-server State Machine (DISCONNECTED, CONNECTING, AUTHENTICATING, etc.).
15.2 [x] Implement heartbeat logic via AMI `Ping` every 15s.
15.3 [x] Implement exponential backoff with jitter (100ms - 30s) for reconnection.
15.4 [x] Implement Circuit Breaker to mark connections as fatal after repeated failures.

## 16. Worker & Runtime Model
16.1 [x] Implement graceful shutdown: catch `SIGTERM/SIGINT`, flush buffers, send `Logoff`.
16.2 [x] Ensure strictly non-blocking operation (no `sleep()` or blocking I/O allowed).
16.3 [x] Implement deterministic tick loop (I/O, Parsing, Correlation Sweep, Health).

## 17. Laravel Integration Layer (Adapter)
17.1 [ ] Map configuration arrays to `ServerConfig` and `ClientOptions` DTOs.
17.2 [ ] Bind `AmiClientManager` as a singleton in the service container.
17.3 [ ] Bind default `RoutingStrategyInterface` in the Service Provider.
17.4 [ ] Inject the Laravel/PSR logger into the manager.
17.5 [ ] Provide `Ami` facade for convenient application access.
17.6 [ ] Provide `ami:listen` Artisan command for worker Profile B.
17.7 [ ] (Optional) Implement bridging of AMI events to Laravel's native event system.

## 18. Testing Strategy
18.1 [x] Achieve 100% unit coverage for Parser, Correlation, and Routing logic.
18.2 [x] Implement integration tests using mock socket servers for multi-server scenarios.
18.3 [x] Create Flood Simulation test (10x load) to verify drop policies.
18.4 [x] Create Reconnect Storm simulation to verify backoff/jitter stability.
18.5 [x] Create Parser Corruption test to verify re-sync logic.
18.6 [x] Execute 24h Soak Test to verify zero memory growth.

## 19. Acceptance Criteria
19.1 [ ] Verify Generic Support: Send any AMI action using `GenericAction`.
19.2 [ ] Verify Event Handling: Subscribe to any AMI event by name.
19.3 [ ] Verify Memory Stability: Zero leakage over 24h execution.
19.4 [ ] Verify Node Isolation: Failure of one node does not impact others.
19.5 [ ] Verify Backpressure Safety: Event drops and buffer overflows handled gracefully.
19.6 [ ] Verify Follows Support: `Command` output correctly captured and terminated.
