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

## Append: BATCH-PR-20260226-05

### Phase P0 (Blockers)
None.

### Phase P1 (High)
- [x] PR5-P1-01 (BATCH-PR-20260226-05) Remove transport/reactor error suppression and emit structured errors
Severity: P1
Target files/classes: `src/Transport/TcpTransport.php`, `src/Transport/Reactor.php`
Acceptance criteria:
- No `@` suppression remains on socket/selector operations in transport/reactor code paths.
- On `stream_select`/read/write/connect failure, native error details are captured and logged with `server_key` and operation.
- Transport failure triggers controlled close/retry and increments a transport error metric counter.
Required tests to add:
- PHPUnit integration test: selector failure on a closed socket emits structured warning and schedules reconnect; metric counter increments. Run with `vendor/bin/phpunit`.
- PHPUnit unit test: `TcpTransport::read()` failure logs error details and does not suppress errors. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Transport/selector errors are observable and never silently suppressed.

- [x] PR5-P1-02 (BATCH-PR-20260226-05) Make `Logger::log()` non-throwing and preserve listener isolation
Severity: P1
Target files/classes: `src/Core/Logger.php`, `src/Core/AmiClient.php`
Acceptance criteria:
- `Logger::log()` never throws, even when serialization fails due to malformed UTF-8 or invalid context.
- A minimal plain-text fallback line is emitted when JSON encoding fails.
- Listener exception handling continues dispatch even if logger serialization fails.
Required tests to add:
- PHPUnit unit test: malformed UTF-8 context does not throw and uses fallback output. Run with `vendor/bin/phpunit`.
- PHPUnit test: listener throws and logger serialization fails; connection remains healthy and subsequent event is dispatched. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Listener exceptions cannot break dispatch loops.

### Phase P2 (Medium)
- [x] PR5-P2-01 (BATCH-PR-20260226-05) Validate redaction regex patterns and fail fast
Severity: P2
Target files/classes: `src/Core/SecretRedactor.php`, `src/Core/ClientOptions.php`, `src/Exceptions/InvalidConfigurationException.php`
Acceptance criteria:
- Redaction regex patterns are validated at construction; invalid patterns throw `InvalidConfigurationException`.
- No `@preg_*` suppression remains in redaction logic.
- Invalid pattern detection is logged/metriced deterministically.
Required tests to add:
- PHPUnit unit test: invalid regex in redaction config throws `InvalidConfigurationException`. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Redaction must not be silently disabled by invalid patterns.

- [x] PR5-P2-02 (BATCH-PR-20260226-05) Move hostname resolution out of manager runtime path
Severity: P2
Target files/classes: `src/Cluster/AmiClientManager.php`, `config/*`
Acceptance criteria:
- `gethostbyname()` is not used in manager runtime/tick paths.
- Hostname mode requires pre-resolved IPs or an injected resolver outside runtime-critical paths.
- Startup/tick paths remain non-blocking under hostname configurations.
Required tests to add:
- PHPUnit unit test: hostname configuration without pre-resolved IPs fails fast or requires resolver injection. Run with `vendor/bin/phpunit`.
- PHPUnit integration test: deterministic non-blocking verification under hostname configuration (tick continues while hostname resolution is external). Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- No blocking DNS resolution in runtime-critical paths.

- [x] PR5-P2-03 (BATCH-PR-20260226-05) Replace signal handler `exit(0)` with application hook
Severity: P2
Target files/classes: `src/Cluster/AmiClientManager.php`
Acceptance criteria:
- Signal handler invokes a callback/event hook and returns without terminating the process.
- Default library behavior does not call `exit()`.
- Host application owns shutdown semantics.
Required tests to add:
- PHPUnit unit test: signal handler triggers hook and does not terminate the process. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Library code must not terminate the host process.

### Phase P3 (Low)
None.

## Append: BATCH-PR-20260226-06

### Phase P0 (Blockers)
None.

### Phase P1 (High)
None.

### Phase P2 (Medium)
- [x] PR6-P2-01 (BATCH-PR-20260226-06) Add non-silent fallback reporting when callback exception handler is missing
Severity: P2
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-06) -> P2 - PendingAction callback exception reporting can be silent
Target files/classes: `src/Correlation/PendingAction.php`, `src/Core/Contracts/MetricsCollectorInterface.php`, `src/Core/Logger.php`
Acceptance criteria:
- When completion callback throws and callback exception handler is null, a deterministic fallback signal is emitted (structured log and/or metric increment).
- Fallback signal includes `server_key` (when available), `action_id`, and callback exception class.
- No exception from fallback reporting escapes into tick/correlation flow.
Required tests to add:
- PHPUnit unit test: callback throws + no handler -> fallback telemetry emitted and execution continues. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Callback exception handling is never silent.
- Listener/callback exceptions cannot break dispatch loops.

- [x] PR6-P2-02 (BATCH-PR-20260226-06) Isolate callback exception handler failures and preserve connection health
Severity: P2
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-06) -> P2 - PendingAction callback exception reporting can be silent
Target files/classes: `src/Correlation/PendingAction.php`, `tests/Unit/Core/*`, `tests/Integration/*`
Acceptance criteria:
- If callback exception handler throws, handler failure is captured by fallback telemetry path instead of being swallowed.
- Connection/tick processing continues after callback + handler failure.
- Subsequent actions/events complete successfully after the failure scenario.
Required tests to add:
- PHPUnit integration test: callback throws, handler throws, connection remains healthy and next action completes. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Callback exceptions and callback-handler failures are observable and isolated.

- [x] PR6-P2-03 (BATCH-PR-20260226-06) Enforce parser constructor invariant between `bufferCap` and `maxFrameSize`
Severity: P2
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-06) -> P2 - Parser buffer cap can be set below frame-size requirements
Target files/classes: `src/Protocol/Parser.php`, `src/Exceptions/InvalidConfigurationException.php`
Acceptance criteria:
- Parser constructor validates that `bufferCap` is not smaller than `maxFrameSize` plus framing delimiter bytes.
- Invalid parser size relationships fail fast with typed `InvalidConfigurationException`.
- Validation message includes both configured values to support deterministic diagnosis.
Required tests to add:
- PHPUnit unit test: constructor with `bufferCap < maxFrameSize + delimiter` throws `InvalidConfigurationException`. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Parser configuration cannot accept relationships that desync valid frames before frame-size checks.

- [x] PR6-P2-04 (BATCH-PR-20260226-06) Add parser misconfiguration regression tests to prevent desync churn
Severity: P2
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-06) -> P2 - Parser buffer cap can be set below frame-size requirements
Target files/classes: `tests/Unit/Protocol/ParserTest.php`, `tests/Unit/Protocol/ParserHardeningTest.php`, `src/Protocol/Parser.php`
Acceptance criteria:
- Tests prove invalid size relationship fails at construction and does not enter runtime parse path.
- Tests verify valid large-frame configuration still parses bounded frames without forced desync.
- Tests verify parser delimiter overhead is accounted for in accepted configuration.
Required tests to add:
- PHPUnit unit test: valid boundary case (`bufferCap == maxFrameSize + delimiter`) is accepted.
- PHPUnit unit test: invalid relationship rejects configuration before parsing begins. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Parser settings remain bounded and deterministic under dialer-sized frames.

### Phase P3 (Low)
None.

## Append: BATCH-PR-20260226-07

### Phase P0 (Blockers)
- [x] PR7-P0-01 (BATCH-PR-20260226-07) Enforce session-boundary write-buffer purge on non-graceful close
Severity: P0
Target files/classes: `src/Transport/TcpTransport.php`, `src/Transport/WriteBuffer.php`, `src/Core/AmiClient.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-07) -> P0 - Stale outbound bytes can leak across reconnect session boundaries
Acceptance criteria:
- Non-graceful close path clears or epoch-invalidates unsent write-buffer bytes before reconnect is attempted.
- Reconnect path cannot flush bytes that were queued before the close event.
- Graceful shutdown behavior remains explicit and deterministic.
Required tests to add:
- PHPUnit integration test: disconnect with pending writes, reconnect, and verify no stale action bytes are emitted to the new session. Run with `vendor/bin/phpunit`.
- PHPUnit unit test: non-graceful close invokes write-buffer clear/epoch invalidation exactly once and buffer depth becomes zero for reconnect path. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Pending actions are deterministically failed on disconnect.
- No cross-session action replay after reconnect.

### Phase P1 (High)
- [x] PR7-P1-01 (BATCH-PR-20260226-07) Replace synchronous tick-path stdout logging with bounded non-blocking sink
Severity: P1
Target files/classes: `src/Core/Logger.php`, `src/Core/AmiClient.php`, `src/Core/Contracts/MetricsCollectorInterface.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-07) -> P1 - Tick-path logging must not perform synchronous stdout I/O
Acceptance criteria:
- Tick-path logging no longer uses direct blocking stdout `echo` writes.
- Logger sink has bounded queue/capacity with deterministic drop behavior.
- Log-drop counter is incremented when sink capacity is exceeded.
Required tests to add:
- PHPUnit unit test: sink backpressure/drop path increments log-drop counter and does not throw. Run with `vendor/bin/phpunit`.
- PHPUnit integration test: callback/listener exception logging under blocked sink does not block tick progression for subsequent processing. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Tick loop remains non-blocking under logging backpressure.
- Listener exceptions cannot break dispatch loops.

- [x] PR7-P1-02 (BATCH-PR-20260226-07) Add event-drop log coalescing and interval rate limit
Severity: P1
Target files/classes: `src/Core/AmiClient.php`, `src/Core/EventQueue.php`, `src/Core/ClientOptions.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-07) -> P1 - Per-drop warning logs create amplification storms under queue pressure
Acceptance criteria:
- Event-drop warnings are emitted as interval summaries with `dropped_delta`, `queue_depth`, and `server_key`.
- Per-event drop warnings are removed from hot path.
- Precise dropped-event counters remain monotonic and accurate.
Required tests to add:
- PHPUnit unit test: burst drop scenario emits at most one warning per configured interval while dropped counter equals total drops. Run with `vendor/bin/phpunit`.
- PHPUnit integration test: sustained flood does not exceed configured logging rate budget and processing continues. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Queue drops remain observable.
- Drop logging cannot dominate tick time under burst load.

- [x] PR7-P1-03 (BATCH-PR-20260226-07) Eliminate busy-spin in Laravel `ami:listen` runtime loop
Severity: P1
Target files/classes: `src/Laravel/Commands/ListenCommand.php`, `config/ami-client.php`, `tests/Integration/*`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-07) -> P1 - Laravel listener loop busy-spins without blocking/yield
Acceptance criteria:
- Worker loop uses bounded blocking tick (`tickAll(10-50)` or configured equivalent) or deterministic idle sleep/yield path.
- Loop cadence is configurable and validated at startup.
- Idle loop no longer consumes near-100% CPU in normal no-event conditions.
Required tests to add:
- PHPUnit integration test: idle listen loop executes bounded iterations with configured wait and does not busy-spin. Run with `vendor/bin/phpunit`.
- PHPUnit unit test: invalid loop cadence config is rejected with typed configuration exception. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Core remains framework-agnostic (no `Illuminate\*` outside `src/Laravel/`).
- Runtime loop behavior is deterministic and bounded.

### Phase P2 (Medium)
- [x] PR7-P2-01 (BATCH-PR-20260226-07) Enforce explicit non-blocking timeout semantics at runtime API boundary
Severity: P2
Target files/classes: `src/Cluster/AmiClientManager.php`, `src/Transport/Reactor.php`, `src/Transport/TcpTransport.php`, `src/Core/ClientOptions.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-07) -> P2 - Non-blocking runtime contract is not enforced at API boundary
Acceptance criteria:
- Runtime-critical APIs reject or clamp blocking timeout inputs in production non-blocking mode.
- If blocking mode is supported, it is exposed as explicit separate API path and cannot be used accidentally by runtime loop.
- Timeout behavior is documented and configuration-validated.
Required tests to add:
- PHPUnit unit test: runtime non-blocking mode with `timeoutMs > 0` is clamped/rejected deterministically. Run with `vendor/bin/phpunit`.
- PHPUnit integration test: `tickAll()` in production mode keeps non-blocking behavior across multiple nodes when caller passes non-zero timeout. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Tick loop fully non-blocking in production runtime mode.
- Multi-server fairness under per-tick budgets remains intact.

### Phase P3 (Low)
None.

## Append: BATCH-PR-20260226-08

### Phase P0 (Blockers)
None.

### Phase P1 (High)
- [x] PR8-P1-01 (BATCH-PR-20260226-08) Enforce always-non-blocking runtime timeouts
Severity: P1
Target files/classes: `src/Cluster/AmiClientManager.php`, `src/Transport/Reactor.php`, `src/Transport/TcpTransport.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-08) -> P1 - Tick loop non-blocking invariant is not enforced
Acceptance criteria:
- Production runtime paths clamp or reject any non-zero timeout inputs and always return `0` for runtime select timeouts.
- Blocking opt-out flags are removed from production paths or isolated into explicit dev/test-only components.
- Non-blocking behavior is documented and enforced consistently across manager/reactor/transport.
Required tests to add:
- PHPUnit unit test: non-blocking invariant fails if any production `normalizeTimeoutMs()` or equivalent returns non-zero. Run with `vendor/bin/phpunit`.
- PHPUnit integration test: tick loop remains non-blocking across multiple nodes even when caller passes non-zero timeout. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Tick loop is fully non-blocking in production runtime paths.
- Multi-server fairness under per-tick budgets remains intact.

### Phase P2 (Medium)
- [x] PR8-P2-01 (BATCH-PR-20260226-08) Record read-timeout failures in circuit breaker
Severity: P2
Target files/classes: `src/Health/ConnectionManager.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-08) -> P2 - Read-timeout failures are not recorded in circuit breaker
Acceptance criteria:
- `recordReadTimeout()` records `recordCircuitFailure('read_timeout')` before scheduling reconnect.
- Circuit-breaker transition logging/metrics are consistent with connect/auth failure paths.
Required tests to add:
- PHPUnit unit test: repeated read timeouts transition circuit breaker to open/half-open per policy. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Reconnect storms cannot monopolize tick time.

- [x] PR8-P2-02 (BATCH-PR-20260226-08) Emit structured, throttled warnings on logger sink drops
Severity: P2
Target files/classes: `src/Core/Logger.php`, `src/Core/AmiClient.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-08) -> P2 - Logger sink drops lack structured warning logs
Acceptance criteria:
- Sink drop reasons (`capacity_exceeded`, `sink_exception`, `sink_write_failed`) emit structured warnings with `server_key`, `queue_depth`, and reason.
- Drop counters continue to increment deterministically for each drop condition.
- Warning logs are throttled to avoid log amplification storms.
Required tests to add:
- PHPUnit unit test: sink drop increments counter and emits one throttled warning with required fields. Run with `vendor/bin/phpunit`.
- PHPUnit unit test: metrics/logging wiring remains intact when sink throws (logger remains non-throwing). Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Logger sink drops are observable (logged and counted).
- Logger must never throw and must not block tick progression.

### Phase P3 (Low)
- [x] PR8-P3-01 (BATCH-PR-20260226-08) Replace logger sink queue with O(1) dequeue structure
Severity: P3
Target files/classes: `src/Core/Logger.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-08) -> P3 - Logger sink queue uses O(n) dequeue
Acceptance criteria:
- Sink queue uses `SplQueue` (or equivalent) to avoid O(n) dequeue reindexing.
- Queue order is preserved under sustained logging.
Required tests to add:
- PHPUnit unit test: queued log entries preserve FIFO order after multiple enqueues/dequeues. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Logger sink queue remains bounded.

- [x] PR8-P3-02 (BATCH-PR-20260226-08) Route PendingAction fallback telemetry through PSR-3 logger
Severity: P3
Target files/classes: `src/Correlation/PendingAction.php`, `src/Correlation/CorrelationRegistry.php`
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-08) -> P3 - PendingAction fallback telemetry bypasses PSR-3 logger
Acceptance criteria:
- Fallback exception telemetry uses PSR-3 logger path instead of `error_log`.
- Structured context is preserved and redaction policies remain intact.
Required tests to add:
- PHPUnit unit test: callback exception with missing handler emits PSR-3 log entry with required context. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Listener exceptions cannot break dispatch loops.

## Append: BATCH-PR-20260226-09

### Phase P0 (Blockers)
None.

### Phase P1 (High)
- [ ] PR9-P1-01 (BATCH-PR-20260226-09) Implement bounded worker cadence for `ami:listen` loop
Severity: P1
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-09) -> P1 - Worker cadence controls are effectively disabled (hot-spin risk)
Target files/classes: `src/Laravel/Commands/ListenCommand.php`, `src/Cluster/AmiClientManager.php`, `src/Transport/Reactor.php`, `config/ami-client.php`
Acceptance criteria:
- `ami:listen` loop honors a bounded cadence (timeout or idle-yield) with production-safe defaults.
- Cadence configuration is validated at startup and rejects invalid values with a typed exception.
- Idle loop no longer hot-spins in no-event conditions.
Required tests to add:
- PHPUnit integration test: idle listen loop executes bounded iterations with configured cadence and does not hot-spin. Run with `vendor/bin/phpunit`.
- PHPUnit unit test: invalid cadence configuration is rejected deterministically. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Core remains framework-agnostic (no `Illuminate\*` outside `src/Laravel/`).
- Tick loop remains non-blocking in production runtime paths.

### Phase P2 (Medium)
- [ ] PR9-P2-01 (BATCH-PR-20260226-09) Align `timeoutMs` contract across public APIs and runtime behavior
Severity: P2
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-09) -> P2 - `timeoutMs` API contract is misleading (ignored end-to-end)
Target files/classes: `src/Core/Contracts/AmiClientInterface.php`, `src/Core/Contracts/TransportInterface.php`, `src/Cluster/AmiClientManager.php`, `src/Transport/Reactor.php`, `src/Laravel/Commands/ListenCommand.php`
Acceptance criteria:
- Chosen timeout contract is enforced end-to-end (either honored where safe or explicitly rejected/clamped everywhere).
- Public interface signatures and documentation match runtime behavior.
- Runtime behavior is deterministic for `timeoutMs` inputs (no silent ignores).
Required tests to add:
- PHPUnit unit test: `tick(timeoutMs)` behavior matches the declared contract across manager/reactor/transport. Run with `vendor/bin/phpunit`.
- PHPUnit integration test: caller-supplied `timeoutMs` produces the expected runtime behavior across multiple nodes. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Tick loop fully non-blocking in production runtime paths.
- Multi-server fairness under per-tick budgets remains intact.

- [ ] PR9-P2-02 (BATCH-PR-20260226-09) Add hostname resolver injection support to `ConfigLoader`
Severity: P2
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-09) -> P2 - ConfigLoader cannot inject hostname resolver for non-IP endpoints
Target files/classes: `src/Cluster/ConfigLoader.php`, `src/Cluster/AmiClientManager.php`, `tests/Integration/*`
Acceptance criteria:
- `ConfigLoader::load()` accepts a hostname resolver (or pre-resolved hostnames) when `enforce_ip_endpoints` is disabled.
- Hostname-based endpoints are bootstrapped successfully without manual manager wiring.
- Resolver behavior is deterministic and does not perform blocking DNS in tick paths.
Required tests to add:
- PHPUnit integration test: hostname endpoint bootstraps via injected resolver and connects as expected. Run with `vendor/bin/phpunit`.
- PHPUnit unit test: hostname endpoint without resolver fails fast with typed configuration exception when required. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Non-blocking runtime paths do not perform DNS resolution inside tick/reconnect.
- Configuration validation remains deterministic and typed.

- [ ] PR9-P2-03 (BATCH-PR-20260226-09) Validate critical numeric option ranges in `ClientOptions`
Severity: P2
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-09) -> P2 - Numeric option ranges are not validated at load time
Target files/classes: `src/Cluster/ClientOptions.php`, `src/Health/ConnectionManager.php`, `src/Exceptions/InvalidConfigurationException.php`
Acceptance criteria:
- All critical numeric options (timeouts, reconnect attempts, queue/buffer limits) are range-validated at construction time.
- Invalid values fail fast with `InvalidConfigurationException` and include the offending key/value in the message.
- Validation is centralized and reused by dependent constructors where applicable.
Required tests to add:
- PHPUnit unit test matrix: zero/negative/out-of-range values throw `InvalidConfigurationException` with deterministic messages. Run with `vendor/bin/phpunit`.
- PHPUnit unit test: valid boundary values are accepted without side effects. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Memory safety caps remain enforced and bounded.
- Reconnect storm protections remain intact under valid configuration.

### Phase P3 (Low)
- [ ] PR9-P3-01 (BATCH-PR-20260226-09) Make `enforceIpEndpoints` behavior explicit and consistent in `TcpTransport`
Severity: P3
Plan ref: Delta 2026-02-26 (BATCH-PR-20260226-09) -> P3 - `enforceIpEndpoints` flag does not change transport behavior
Target files/classes: `src/Transport/TcpTransport.php`, `src/Cluster/ClientOptions.php`, `tests/Unit/Transport/*`
Acceptance criteria:
- Transport behavior matches configuration: either enforce IP-only everywhere or permit hostnames when `enforceIpEndpoints` is disabled.
- Configuration ambiguity is removed and behavior is documented in code comments where necessary.
- Endpoint validation paths are covered by deterministic tests.
Required tests to add:
- PHPUnit unit test: hostname endpoint behavior matches `enforceIpEndpoints` configuration. Run with `vendor/bin/phpunit`.
Invariants that must remain true:
- Transport endpoint policy is deterministic and consistent across code paths.
