# APN AMI Client: Implementation Task Checklist

## Phase 0 --- Architectural Corrections
1. [x] Define Composer name `apn/ami-client` and PSR-4 namespace `Apn\AmiClient\`.
2. [x] Set minimum PHP version 8.4+ in `composer.json`.
3. [x] Establish directory structure (`src/Core`, `src/Cluster`, `src/Protocol`, etc.) as per Section 1.
4. [x] Ensure Core layers remain strictly framework-agnostic (no `Illuminate\*` imports).
5. [x] Remove any ActionID logic from Transport layer (Transport must only handle byte I/O).
6. [x] Ensure Transport only handles byte I/O.
7. [x] Move correlation responsibilities (ActionID generation, mapping) to Correlation/Client layer.
8. [x] Implement `AmiClientManager` construction with `ServerRegistry`, `ClientOptions`, and `LoggerInterface`.

## Phase 1 --- Core Transport & Parser Hardening
1. [x] Implement `TransportInterface` with `read()`, `write()`, and `getStream()` for multiplexing.
2. [x] Implement outbound `WriteBuffer` with partial write handling.
3. [x] Enforce per-server write buffer caps (default 5MB) and throw `BackpressureException`.
4. [x] Parser: Handle both `\r\n\r\n` and defensive `\n\n` as frame boundaries.
5. [x] Parser: Enforce strict 64KB hard limit for individual AMI frames.
6. [x] Parser: Enforce strict per-server buffer cap (e.g., 2MB) for the cumulative parser buffer.
7. [x] Parser: Implement desync recovery (discard bytes until next double-newline).
8. [x] Parser: Implement key normalization (lowercase) and duplicate key handling (arrays).
9. [x] Add unit tests for parser corruption resync and buffer limits.
10. [x] Implement secret redaction for `Secret`, `Password`, and sensitive AMI variables in logs.

## Phase 2 --- Correlation & Completion Strategy
1. [x] Define `CompletionStrategyInterface` contract fields:
    - `max_duration_ms`
    - `max_messages`
    - `terminal_event_names`
    - `timeout` behavior
2. [x] Implement `CorrelationManager` for globally unique ActionID generation.
3. [x] Enforce formalized ActionID contract: `{server_key}:{instance_id}:{sequence_id}`.
4. [x] Implement `SingleResponseStrategy`.
5. [x] Implement `MultiEventStrategy`.
6. [x] Implement `FollowsResponseStrategy`.
7. [x] Implement immutable `GenericAction` DTO with support for strategy overrides.
8. [x] Add timeout tests and overflow tests for completion strategies.

## Phase 3 --- Cluster Multiplexing & Fairness
1. [x] Implement cluster-level `stream_select()` aggregation.
2. [x] Implement `max_frames_per_tick` budget.
3. [x] Implement `max_events_per_tick` budget.
4. [x] Implement `max_bytes_read_per_tick` budget.
5. [x] Implement `max_connect_attempts_per_tick` throttling.
6. [x] Enforce per-server event queue caps.
7. [x] Add unit and integration tests for fairness.

## Phase 4 --- Health, Reconnect & Isolation
1. [x] Implement explicit connection state machine.
2. [x] Implement explicit login/authentication state machine.
3. [x] Add login timeout enforcement.
4. [x] Implement banner handling.
5. [x] Prevent event dispatch before AUTHENTICATING completes.
6. [x] Implement exponential backoff with jitter for reconnection.
7. [x] Implement cluster-wide connection attempt throttling (Reconnect Storm protection).
8. [x] Validate isolation under simulated parser corruption.
9. [x] Validate isolation during reconnect storm simulation.
10. [x] Add integration tests for login failure + recovery.

## Phase 5 --- Laravel Adapter Integration
1. [x] Map configuration arrays to `ServerConfig` and `ClientOptions` DTOs.
2. [x] Bind `AmiClientManager` as a singleton in the service container.
3. [x] Provide `Ami` facade and `ami:listen` Artisan command for worker Profile B.
4. [x] Document recommended dedicated `ami:listen` pattern (Event Bridge).
5. [x] Add warning section about connection explosion in Laravel topology documentation.
6. [x] Provide configuration example for Redis event bridge integration.

## Phase 6 --- Stability & Soak Testing
1. [x] Achieve 100% unit coverage for Parser, Correlation, and Routing logic.
2. [x] Execute Flood Simulation (10x normal load) to verify drop policy and fairness.
3. [x] Execute Reconnect Storm simulation to verify backoff/jitter stability.
4. [x] Execute 24h Soak Test to verify zero memory growth.

## Action Strategy Roadmap
1. [x] v1 Core (Minimal): Login, Logoff, Ping, GenericAction.
2. [x] v1.5 Common Dialer Extensions: Originate, Hangup, Redirect, SetVar, GetVar, Command (Follows).
3. [x] v2 Extended Action Set: QueueStatus, Status, QueueSummary, PJSIPShowEndpoint, etc.

## Acceptance Criteria
1. [ ] Verify Generic Support: Send any AMI action using `GenericAction`.
2. [ ] Verify Event Handling: Subscribe to any AMI event by name.
3. [ ] Verify Memory Stability: Zero leakage over 24h execution (verified via soak test).
4. [ ] Verify Node Isolation: Failure/flood of one node does not impact others.
5. [ ] Verify Backpressure Safety: Event drops and buffer overflows handled gracefully.
6. [ ] Verify Follows Support: `Command` output correctly captured and terminated.
7. [ ] Verify Cluster Fairness: Noisy nodes do not starve others.
8. [ ] Verify Reconnect Storm Mitigation: Cluster-wide throttling and jittered backoff working.
9. [ ] Verify ActionID Integrity: All ActionIDs follow the `{server}:{instance}:{seq}` contract and are generated above the transport layer.

## Production Readiness Checklist (Audit-Driven)

### Phase P0 (Blockers)
1. [x] Task ID: `PR-P0-01` | Severity: `P0` | Target: `src/Core/AmiClient.php`, `src/Health/*` | Implement explicit async connect state machine `DISCONNECTED -> CONNECTING -> CONNECTED -> AUTHENTICATING -> READY` per node. Acceptance: state transitions are deterministic and covered by unit tests.
2. [x] Task ID: `PR-P0-02` | Severity: `P0` | Target: `src/Transport/StreamTransport.php`, `src/Core/AmiClient.php` | Replace blocking connect path with `STREAM_CLIENT_ASYNC_CONNECT` and complete handshake via write-readiness + socket error checks in tick flow. Acceptance: no blocking socket connect call remains in `processTick()`/`tick()` path.
3. [x] Task ID: `PR-P0-03` | Severity: `P0` | Target: `src/Cluster/AmiClientManager.php`, `src/Core/AmiClient.php`, `tests/Integration/*` | Add regression test for non-blocking connect under unreachable host / slow SYN path while tick continues servicing other nodes. Acceptance: at least one healthy node continues read/write work while another node remains in `CONNECTING`.
4. [x] Task ID: `PR-P0-04` | Severity: `P0` | Target: `src/Core/ClientOptions.php`, `src/Health/*`, `config/*` | Redefine `connectTimeout` as maximum `CONNECTING` duration (non-blocking). Acceptance: timeout triggers reconnect scheduling without blocking a tick.
5. [x] Task ID: `PR-P0-05` | Severity: `P0` | Target: `src/Cluster/AmiClientManager.php`, `src/Health/ReconnectScheduler.php`, `tests/Integration/*` | Implement reconnect fairness using a round-robin reconnect cursor that cannot starve later nodes when `maxConnectAttemptsPerTick` is capped. Acceptance: starvation regression test with >N nodes confirms eventual attempt distribution across all eligible nodes.

### Phase P1 (High)
1. [x] Task ID: `PR-P1-01` | Severity: `P1` | Target: `src/Core/AmiClient.php` | Wrap each event listener invocation in `try/catch` and continue dispatch after failures. Acceptance: listener throw isolation test proves one throwing listener does not block other listeners/events.
2. [x] Task ID: `PR-P1-02` | Severity: `P1` | Target: `src/Cluster/AmiClientManager.php` | Apply identical per-listener exception isolation in manager-level listener fan-out. Acceptance: manager-level listener throw does not stop remaining listeners or server processing.
3. [x] Task ID: `PR-P1-03` | Severity: `P1` | Target: `src/Core/AmiClient.php`, `src/Exceptions/*`, `tests/Unit/*` | Enforce `send()` gating: allow only in `READY`, otherwise throw typed exception containing `server_key` and connection state. Acceptance: tests verify `send()` while `DISCONNECTED`/`AUTHENTICATING` fails fast with typed exception.
4. [x] Task ID: `PR-P1-04` | Severity: `P1` | Target: `src/Core/*`, `src/Health/*` | Standardize reconnect/connect failure logging fields: `server_key`, `host`, `port`, `attempt`, `backoff`, `next_retry_at`. Acceptance: integration logs include all mandatory fields for failed connect and scheduled retry.
5. [x] Task ID: `PR-P1-05` | Severity: `P1` | Target: `scripts/*`, `composer.json`, CI workflow files | Add/keep CI guard enforcing no `Illuminate\\*` imports outside `src/Laravel/`. Acceptance: CI fails when framework imports appear in core namespaces and passes for valid adapter-only usage.

### Phase P2 (Medium)
1. [x] Task ID: `PR-P2-01` | Severity: `P2` | Target: `src/Core/ClientOptions.php`, `src/Core/AmiClient.php`, `docs/*` | Implement `readTimeout` as idle-read liveness threshold for non-blocking loop (not blocking socket timeout) and document behavior consistently. Acceptance: idle read threshold triggers health/reconnect policy and docs/config semantics match implementation.
2. [x] Task ID: `PR-P2-02` | Severity: `P2` | Target: `src/Health/CircuitBreaker.php`, `src/Health/*`, `config/*` | Implement per-node circuit breaker states `CLOSED`, `OPEN`, `HALF_OPEN` with configurable failure threshold and cooldown. Acceptance: breaker opens after threshold failures and blocks normal reconnect attempts while open.
3. [x] Task ID: `PR-P2-03` | Severity: `P2` | Target: `src/Health/CircuitBreaker.php`, `src/Cluster/AmiClientManager.php`, `tests/Integration/*` | Implement `HALF_OPEN` probe rules (limited probes, close on success, reopen on failure) and transition logging. Acceptance: integration tests validate probe behavior and logs include breaker transition reason/counters.

### Phase P3 (Low/Polish)
1. [x] Task ID: `PR-P3-01` | Severity: `P3` | Target: `src/*` logging call sites, `docs/*` | Standardize `queue_depth` field naming and presence across queue/backpressure/reconnect logs. Acceptance: log contract examples and tests show consistent `queue_depth` output where queue metrics are emitted.





