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

## Append: BATCH-PR-20260226-02

### Phase P1
1. [x] PR2-P1-01 (BATCH-PR-20260226-02) Target: `src/Protocol/Parser.php`, `src/Core/ClientOptions.php` | Make Parser max frame size configurable via `ClientOptions`; safe default; bounded caps. Acceptance: default supports large AMI payloads; cap still enforced; docs updated.
2. [x] PR2-P1-02 (BATCH-PR-20260226-02) Target: `tests/Unit/Protocol/*`, `tests/Integration/*`, `src/Protocol/Parser.php` | Add parser tests: large frame accepted under limit; oversize triggers controlled failure; parser recovers/no corruption. Acceptance: tests cover accept/reject paths and verify recovery after oversize frame.
3. [x] PR2-P1-03 (BATCH-PR-20260226-02) Target: `src/Correlation/CorrelationRegistry.php`, `src/Exceptions/*` | Fix CorrelationRegistry semantics: no synthetic success when response missing; allow only event-only strategies. Acceptance: missing response yields typed failure or timeout; synthetic response only for explicitly event-only strategies.
4. [x] PR2-P1-04 (BATCH-PR-20260226-02) Target: `tests/Unit/Correlation/*`, `tests/Integration/*`, `src/Correlation/CorrelationRegistry.php` | Add correlation tests: missing response => typed failure; event-only strategy completes without response (explicitly). Acceptance: tests assert missing response failures and event-only success path.

### Phase P2
1. [x] PR2-P2-01 (BATCH-PR-20260226-02) Target: `src/Core/Logger.php`, `src/Core/AmiClient.php`, `docs/*` | Standardize `queue_depth` logging (normalize to null and enforce in queue/backpressure logs). Acceptance: queue-related log entries consistently include `queue_depth` (or null) in documented categories.
2. [x] PR2-P2-02 (BATCH-PR-20260226-02) Target: `src/Core/SecretRedactor.php`, `src/Core/ClientOptions.php`, `config/*` | Expand/configure secret redaction policy; regex matching; `ClientOptions` injection. Acceptance: default redaction masks password/secret/token/auth/key patterns; policy is configurable.
3. [x] PR2-P2-03 (BATCH-PR-20260226-02) Target: `tests/Unit/Logging/*`, `tests/Unit/Core/*`, `src/Core/SecretRedactor.php` | Add redaction tests (password/token/auth/key fields and regex matches). Acceptance: tests cover key list and regex matching for redaction.
4. [x] PR2-P2-04 (BATCH-PR-20260226-02) Target: `src/Correlation/ActionIdGenerator.php`, `src/Core/ClientOptions.php` | Bound ActionID length with truncation+hash scheme; preserve uniqueness. Acceptance: generated ActionIDs are bounded; truncation preserves uniqueness via stable hash suffix.
5. [x] PR2-P2-05 (BATCH-PR-20260226-02) Target: `tests/Unit/Correlation/*`, `src/Correlation/ActionIdGenerator.php` | Add ActionID tests: long `server_key` produces bounded ActionID; uniqueness preserved across nodes. Acceptance: tests assert max length and uniqueness across different `server_key` inputs.

## Append: BATCH-PR-20260226-03

### Phase P1
1. [x] PR3-P1-01 (BATCH-PR-20260226-03) Remove hidden blocking from close() and redesign shutdown as non-blocking state machine. Severity: P1. Targets: `src/Core/AmiClient.php` (close/shutdown path), any shutdown helpers. Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-03): Non-Blocking Shutdown + Correlation Transactionality + Callback Isolation + Metrics Wiring. Acceptance: close() returns immediately (no internal tick/select wait); shutdown completes via normal tick progression or bounded deadline. Tests: close() does not block; tick loop continues under simulated churn.
2. [x] PR3-P1-02 (BATCH-PR-20260226-03) Define/implement production hostname policy (prefer IP or bootstrap pre-resolution; never resolve in tick). Severity: P1. Targets: `src/Transport/TcpTransport.php` connect path; options/docs. Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-03): Non-Blocking Shutdown + Correlation Transactionality + Callback Isolation + Metrics Wiring. Acceptance: no hostname resolution occurs inside tick/connect hot path OR endpoints require IP in production mode. Tests: connecting with hostname does not block tick (use test double / documented policy test).
3. [x] PR3-P1-03 (BATCH-PR-20260226-03) Make sendInternal transactional: rollback/fail pending action if transport->send() throws (backpressure). Severity: P1. Targets: `src/Core/AmiClient.php` sendInternal; `src/Correlation/CorrelationRegistry.php` rollback API. Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-03): Non-Blocking Shutdown + Correlation Transactionality + Callback Isolation + Metrics Wiring. Acceptance: no orphan pending action exists after send failure; typed exception returned to caller with actionable context. Tests: transport send throws -> correlation registry count unchanged / actionId removed; no false timeout noise for rolled-back action.
4. [x] PR3-P1-04 (BATCH-PR-20260226-03) Isolate pending completion callbacks: exceptions in user callbacks do not tear down tick/connection. Severity: P1. Targets: `src/Correlation/PendingAction.php` notify(); call sites in AmiClient processing loop. Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-03): Non-Blocking Shutdown + Correlation Transactionality + Callback Isolation + Metrics Wiring. Acceptance: callback exceptions are caught, logged, and counted; client continues processing subsequent frames/events. Tests: callback throws -> connection remains healthy; subsequent action completes normally.

### Phase P2
1. [x] PR3-P2-01 (BATCH-PR-20260226-03) Add MetricsCollectorInterface injection to AmiClientManager and propagate through createClient() stack. Severity: P2. Targets: `src/Cluster/AmiClientManager.php`; `src/Core/AmiClient.php`; `src/Core/EventQueue.php`; `src/Health/ConnectionManager.php`. Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-03): Non-Blocking Shutdown + Correlation Transactionality + Callback Isolation + Metrics Wiring. Acceptance: default wiring supports non-null metrics collector injection; counters increment in relevant paths (drops/backpressure/reconnect/callback_exception). Tests: manager with test metrics collector sees increments for a simulated drop/backpressure event.
2. [x] PR3-P2-02 (BATCH-PR-20260226-03) Validate EventQueue capacity >= 1 and throw typed config exception on invalid value. Severity: P2. Targets: `src/Core/EventQueue.php`. Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-03): Non-Blocking Shutdown + Correlation Transactionality + Callback Isolation + Metrics Wiring. Acceptance: capacity <= 0 fails fast with typed exception. Tests: constructing with 0/negative capacity throws expected exception.

### Phase P3
1. [x] PR3-P3-01 (BATCH-PR-20260226-03) Replace shutdown/logoff swallow with structured debug/warn telemetry. Severity: P3. Targets: `src/Core/AmiClient.php` logoff/close exception handling. Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-03): Non-Blocking Shutdown + Correlation Transactionality + Callback Isolation + Metrics Wiring. Acceptance: swallow paths log structured context (server_key, reason, exception class/message where safe). Tests: optional (if logging tests exist); otherwise verify via code-level assertions / documented behavior.


## Append: BATCH-PR-20260226-04

### Phase P1 (High)
- [x] PR4-P1-01 (BATCH-PR-20260226-04) Enforce non-blocking hostname policy for reconnect paths. Severity: P1. Targets: `src/Transport/TcpTransport.php`, `src/Core/AmiClient.php`, `src/Core/ClientOptions.php`, `config/*`. Acceptance criteria:
- Enforce IP-only endpoints in production mode or pre-resolve hostnames at bootstrap; no blocking DNS calls occur inside tick/reconnect paths.
- If hostnames are allowed, resolution is cached and performed outside tick, and reconnect path uses only pre-resolved IPs.
- Configuration validation fails fast when hostname endpoints are supplied while IP-only mode is enforced.
Required tests to add:
- PHPUnit: configuration policy test that rejects hostname endpoints when IP-only mode is enabled. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Tick loop fully non-blocking (no DNS/blocking I/O inside tick/reconnect paths).

- [x] PR4-P1-02 (BATCH-PR-20260226-04) Add deterministic non-blocking verification test for reconnect with hostnames. Severity: P1. Targets: `tests/Integration/*`, `tests/Unit/*`. Acceptance criteria:
- Test simulates a hostname endpoint with blocked DNS resolution or delayed resolution and verifies tick loop continues to service other nodes.
- Test asserts no blocking call is made inside tick by verifying a bounded max tick duration under forced reconnect.
Required tests to add:
- PHPUnit integration test: hostname reconnect path does not block tick; other node continues read/write. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Tick loop fully non-blocking (no DNS/blocking I/O inside tick/reconnect paths).

### Phase P2 (Medium)
- [x] PR4-P2-01 (BATCH-PR-20260226-04) Verify async connect success when socket helpers are unavailable. Severity: P2. Targets: `src/Transport/TcpTransport.php`. Acceptance criteria:
- When ext-sockets helpers are unavailable, async connect success is verified via a portable fallback check.
- If verification cannot be performed, connection is treated as failed and socket is closed.
- Connection state does not transition to connected on unverified async connect.
Required tests to add:
- PHPUnit unit test: simulate absence of socket helpers and assert connect does not report success without verification. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Connection state only transitions to connected after verified async-connect success.

- [x] PR4-P2-02 (BATCH-PR-20260226-04) Add async-connect fallback tests for false-positive prevention. Severity: P2. Targets: `tests/Unit/Transport/*`, `tests/Integration/*`. Acceptance criteria:
- Test covers fallback verification path using stream metadata and non-blocking write probe.
- Test asserts that failed verification yields immediate failure and reconnect scheduling.
Required tests to add:
- PHPUnit unit test: fallback verification failure does not mark connected. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- No synthetic connected state when verification fails or is unavailable.

### Phase P3 (Low)
- [x] PR4-P3-01 (BATCH-PR-20260226-04) Add configurable value-based redaction and apply before logging payloads. Severity: P3. Targets: `src/Core/SecretRedactor.php`, `src/Core/Logger.php`, `src/Core/AmiClient.php`, `src/Core/ClientOptions.php`, `config/*`. Acceptance criteria:
- Redactor supports configurable regex-based value patterns in addition to key-based redaction.
- Action/event payloads are passed through value-based redactor before logging structured context.
- Defaults are safe and do not remove non-secret values.
Required tests to add:
- PHPUnit unit test: value-based regex redacts secrets embedded in free-form strings. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Logs must not expose secrets embedded in values.

- [x] PR4-P3-02 (BATCH-PR-20260226-04) Add nested-context value redaction coverage. Severity: P3. Targets: `tests/Unit/Core/*`, `tests/Unit/Logging/*`. Acceptance criteria:
- Redaction applies to nested arrays and mixed key/value contexts.
- Non-secret values remain unchanged.
Required tests to add:
- PHPUnit unit test: nested context arrays with embedded secrets are redacted. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Logs must not expose secrets embedded in values.
