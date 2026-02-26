# Production Readiness Audit 008: `src/`

## 1. Executive Summary
- **Production readiness verdict:** **Nearly Ready (86%)**.
- **Top strength #1:** Core architecture remains framework-agnostic; `Illuminate\*` usage is isolated to `src/Laravel/*` (no matches in Core/Protocol/Transport/Correlation/Events/Cluster/Exceptions).
- **Top strength #2:** Correlation lifecycle is strongly deterministic: pending actions are bounded, timeout-swept, and failed on disconnect (`src/Correlation/CorrelationRegistry.php:63`, `src/Correlation/CorrelationRegistry.php:163`, `src/Correlation/CorrelationRegistry.php:181`; disconnect fail-all wiring in `src/Core/AmiClient.php:393`, `src/Core/AmiClient.php:816`, `src/Core/AmiClient.php:830`).
- **Top strength #3:** Multi-server reconnect fairness and anti-storm controls are present: rotating reconnect cursor and capped connect attempts per tick (`src/Cluster/AmiClientManager.php:241`, `src/Cluster/AmiClientManager.php:247`, `src/Cluster/AmiClientManager.php:252`, `src/Cluster/AmiClientManager.php:256`).
- **Top blocker #1 (P1):** Non-blocking behavior is not an always-on invariant; runtime can be configured to permit blocking `stream_select` waits (`src/Cluster/AmiClientManager.php:276`, `src/Cluster/AmiClientManager.php:279`, `src/Transport/TcpTransport.php:373`, `src/Transport/TcpTransport.php:376`, `src/Transport/Reactor.php:224`, `src/Transport/Reactor.php:227`).
- **Top blocker #2 (P2):** Read-timeout reconnects do not feed circuit-breaker failure accounting, weakening herd-control consistency compared to connect/auth failures (`src/Health/ConnectionManager.php:246`, `src/Health/ConnectionManager.php:256`, `src/Health/ConnectionManager.php:265`).
- **Top blocker #3 (P2):** Logger drop visibility is metric-only for sink-capacity/sink-write failures; no structured log event is emitted when drops occur (`src/Core/Logger.php:141`, `src/Core/Logger.php:163`, `src/Core/Logger.php:171`).
- **Top blocker #4 (P3):** Logger sink queue uses `array_shift` in drain loop, creating O(n) copy cost under sustained logging bursts (`src/Core/Logger.php:184`).
- **Top blocker #5 (P3):** Correlation fallback telemetry for callback exceptions uses `error_log` directly (not unified PSR-3 path), reducing consistency of operational observability (`src/Correlation/PendingAction.php:165`, `src/Correlation/PendingAction.php:167`).
- **Hard invariants compliance summary:** 9/10 pass; **1 fail** (`tick loop fully non-blocking`), so package cannot be marked fully production-ready yet.

## 2. Scorecard (0–5)
- Architecture/Boundaries: **4.7/5**
- Non-blocking I/O correctness: **3.4/5**
- Parser robustness: **4.6/5**
- Correlation correctness: **4.8/5**
- Event backpressure: **4.6/5**
- Reconnect/Health resilience: **4.2/5**
- Logging/Security: **4.1/5**
- Failure semantics integrity: **4.6/5**
- Testability/Extensibility: **4.2/5**

## 3. Findings (Evidence-Based)

### Finding 1
- **Severity:** P1 high
- **Location:** `src/Cluster/AmiClientManager.php:276`, `src/Cluster/AmiClientManager.php:279`, `src/Transport/TcpTransport.php:373`, `src/Transport/TcpTransport.php:376`, `src/Transport/Reactor.php:224`, `src/Transport/Reactor.php:227`
- **Why it matters (dialer impact):** For 24/7 dialers, a strict non-blocking tick loop is a hard safety invariant. Any runtime opt-out that allows blocking waits can stall event handling, timeout sweeps, and reconnect fairness under operator misconfiguration.
- **Concrete fix (code-level direction):** Remove opt-out for production path: force `normalizeRuntimeTimeoutMs()`/`normalizeTimeoutMs()` to return `0` unconditionally in manager/reactor/transport, or split explicit dev/test components so production classes cannot block.

### Finding 2
- **Severity:** P2 medium
- **Location:** `src/Health/ConnectionManager.php:246`, `src/Health/ConnectionManager.php:256`, `src/Health/ConnectionManager.php:265`
- **Why it matters (dialer impact):** Circuit breaker is updated on connect/login failures, but not on read timeouts. In flaky links, repeated read timeouts can continue reconnect churn without leveraging breaker open-state dampening as consistently as other failure modes.
- **Concrete fix (code-level direction):** Add `recordCircuitFailure('read_timeout')` in `recordReadTimeout()` before scheduling next reconnect; include transition logging/metrics parity with other failure reasons.

### Finding 3
- **Severity:** P2 medium
- **Location:** `src/Core/Logger.php:141`, `src/Core/Logger.php:163`, `src/Core/Logger.php:171`
- **Why it matters (dialer impact):** Queue/drop conditions are counted via metrics, but no structured warning is emitted. During incident response, operators relying on logs only can miss active log-loss conditions.
- **Concrete fix (code-level direction):** Emit throttled warning logs for sink drops (`capacity_exceeded`, `sink_exception`, `sink_write_failed`) with `server_key`, `queue_depth`, and reason.

### Finding 4
- **Severity:** P3 low
- **Location:** `src/Core/Logger.php:184`
- **Why it matters (dialer impact):** `array_shift()` in a hot path is O(n), adding avoidable CPU overhead under sustained high-volume logging.
- **Concrete fix (code-level direction):** Replace sink queue with `SplQueue` (or head-index ring buffer) to avoid array reindexing during dequeue.

### Finding 5
- **Severity:** P3 low
- **Location:** `src/Correlation/PendingAction.php:165`, `src/Correlation/PendingAction.php:167`
- **Why it matters (dialer impact):** Fallback exception telemetry bypasses the primary logger pipeline, potentially missing redaction/context normalization guarantees.
- **Concrete fix (code-level direction):** Inject a minimal PSR-3 logger into `PendingAction` fallback path (or route fallback through correlation registry logger) instead of `error_log`.

## Hard Invariants Validation
- **Core remains framework-agnostic (no `Illuminate\*` in Core):** **PASS**. `Illuminate\*` imports are in `src/Laravel/*` only (e.g., `src/Laravel/AmiClientServiceProvider.php:13`, `src/Laravel/Ami.php:8`, `src/Laravel/Commands/ListenCommand.php:9`).
- **Tick loop is fully non-blocking:** **FAIL**. Blocking can be enabled by configuration (see Finding 1).
- **No synthetic success when responses are missing:** **PASS** with explicit guard. Synthetic success is only used for strategies explicitly marked `EventOnlyCompletionStrategyInterface` (`src/Correlation/CorrelationRegistry.php:142`, `src/Correlation/CorrelationRegistry.php:152`).
- **Multi-server fairness under capped per-tick budgets:** **PASS**. Rotating reconnect cursor and global per-tick cap implemented (`src/Cluster/AmiClientManager.php:246`, `src/Cluster/AmiClientManager.php:252`, `src/Cluster/AmiClientManager.php:256`).
- **No cross-node ActionID contamination:** **PASS**. Per-node action ID generation includes server segment (`src/Correlation/ActionIdGenerator.php:41`), and correlation is per-client/per-connection registry (`src/Cluster/AmiClientManager.php:440`, `src/Correlation/CorrelationRegistry.php:25`).
- **Memory bounded in buffers/queues/correlation:** **PASS**. Event queue cap (`src/Core/EventQueue.php:48`), write buffer cap (`src/Transport/WriteBuffer.php:32`), parser cap (`src/Protocol/Parser.php:65`), pending cap (`src/Correlation/CorrelationRegistry.php:63`).
- **Pending actions deterministically failed on disconnect/timeout:** **PASS**. Timeout sweep + fail-all on close/force-close (`src/Correlation/CorrelationRegistry.php:163`, `src/Core/AmiClient.php:393`, `src/Core/AmiClient.php:816`, `src/Core/AmiClient.php:830`).
- **Listener exceptions cannot break dispatch loops:** **PASS**. Listener/callback exceptions are isolated (`src/Core/AmiClient.php:529`, `src/Core/AmiClient.php:542`, `src/Cluster/AmiClientManager.php:323`, `src/Correlation/PendingAction.php:133`).
- **Reconnect storms cannot monopolize tick time:** **PASS**. Connection attempts are capped and fairness-cycled (`src/Cluster/AmiClientManager.php:241`, `src/Cluster/AmiClientManager.php:252`; `src/Health/ConnectionManager.php:312`).
- **Queue drops are observable (logged or counted):** **PASS**. Drops are counted and (for event queue) summarized in logs (`src/Core/EventQueue.php:52`, `src/Core/AmiClient.php:738`, `src/Correlation/CorrelationRegistry.php:131`).

## 4. Production Readiness Checklist
- [ ] Must-fix blockers (P0/P1)
- [ ] Enforce always-non-blocking runtime semantics in production code paths (remove blocking opt-out).

- [ ] Recommended improvements (P2/P3)
- [ ] Feed read-timeout failures into circuit-breaker failure accounting.
- [ ] Emit structured/throttled warnings when logger sink drops lines.
- [ ] Replace logger sink queue `array_shift` with queue structure optimized for dequeues.
- [ ] Route pending-action fallback exception telemetry through PSR-3 logger path.

## 5. Suggested Next Steps
1. Enforce non-blocking timeout behavior as a hard invariant in `AmiClientManager`, `Reactor`, and `TcpTransport`.
2. Add unit tests proving non-zero timeout inputs are always clamped to `0` in production runtime path.
3. Update `ConnectionManager::recordReadTimeout()` to record circuit failure and add tests for breaker transitions on repeated read timeouts.
4. Add logger-drop warning throttling and tests asserting both metrics and structured logs are emitted.
5. Replace sink queue implementation with `SplQueue` and add micro-benchmark-style regression test for sustained logging throughput.
6. Route `PendingAction` fallback telemetry to PSR-3 and verify redaction/mandatory context remains intact.
7. Add explicit invariant tests for each hard invariant in this prompt (especially non-blocking, fairness, and synthetic-success guard).
8. Re-run full test suite in an environment that permits loopback bind (`stream_socket_server` on `127.0.0.1:0`).

### Missing tests to add immediately
- A non-blocking invariant test that fails if any production `normalizeTimeoutMs()` returns non-zero.
- A reconnect resilience test that validates circuit breaker open/half-open transitions triggered by repeated read timeouts.
- A logger observability test that verifies sink drops produce both metric increments and warning logs.

### Invariants currently violated
- **Tick loop fully non-blocking** (violated due to configurable opt-out).

## Production Readiness Classification
- **Percentage:** **86%**
- **Classification:** **Nearly Ready**
- **What prevents 100%:** strict enforcement of non-blocking runtime across all production paths, plus improved reconnection failure classification consistency and log-drop visibility hardening.
