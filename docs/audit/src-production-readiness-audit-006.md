# 1. Executive Summary

- **Production readiness verdict:** **Nearly Ready** at **88%**.
- **Top strength 1:** Core remains framework-agnostic; Laravel dependencies are confined to `src/Laravel/*` (`src/Laravel/AmiClientServiceProvider.php:13`, `src/Laravel/Ami.php:8`, `src/Laravel/Commands/ListenCommand.php:8`).
- **Top strength 2:** Non-blocking transport and bounded I/O paths are implemented with async connect, non-blocking sockets, capped reads, and partial-write handling (`src/Transport/TcpTransport.php:82-110`, `src/Transport/TcpTransport.php:251-311`).
- **Top strength 3:** Correlation lifecycle is deterministic on timeout and disconnect, with explicit sweep/fail paths and force-close propagation (`src/Correlation/CorrelationRegistry.php:163-186`, `src/Core/AmiClient.php:350-383`, `src/Core/AmiClient.php:797-805`).
- **Top blocker 1 (P2):** Callback-exception reporting can be silently swallowed when the handler itself fails or is absent (`src/Correlation/PendingAction.php:129-139`).
- **Top blocker 2 (P2):** Parser buffer cap is independent of max frame size, allowing misconfiguration where valid frames can never be parsed before triggering desync (`src/Protocol/Parser.php:27-31`, `src/Protocol/Parser.php:49-55`, `src/Protocol/Parser.php:109-114`).
- **Invariant compliance statement:** All 10 hard invariants are satisfied in current codepaths; no invariant violations detected.

# 2. Scorecard (0-5)

- Architecture/Boundaries: **5/5**
- Non-blocking I/O correctness: **4.5/5**
- Parser robustness: **4/5**
- Correlation correctness: **4.5/5**
- Event backpressure: **4.5/5**
- Reconnect/Health resilience: **4.5/5**
- Logging/Security: **4/5**
- Failure semantics integrity: **4/5**
- Testability/Extensibility: **4/5**

# 3. Findings (Evidence-Based)

## F-001
- **Severity:** P2 medium
- **Location:** `src/Correlation/PendingAction.php:129-139`
- **Why it matters (dialer impact):** If a pending-action callback throws and the callback-exception handler itself fails (or is not set), the error becomes invisible. This hides application-level failure signals and makes incident triage harder in 24/7 workloads.
- **Concrete fix (code-level direction):** Always surface handler failures via a safe fallback (e.g., `error_log` or injected logger/metrics). If `$callbackExceptionHandler` is null, record a metric or log a minimal line instead of silently swallowing.

## F-002
- **Severity:** P2 medium
- **Location:** `src/Protocol/Parser.php:27-31`, `src/Protocol/Parser.php:49-55`, `src/Protocol/Parser.php:109-114`
- **Why it matters (dialer impact):** `bufferCap` can be configured below `maxFrameSize`, which causes valid large frames to desync and force connection resets before the frame-size check is reached. In high-volume dialers this can trigger avoidable reconnect churn.
- **Concrete fix (code-level direction):** Enforce `bufferCap >= maxFrameSize + delimiter` (or set `bufferCap` to at least `maxFrameSize * 2`) in the constructor; throw `InvalidConfigurationException` when the relationship is invalid.

# 4. Production Readiness Checklist

- [ ] Must-fix blockers (P0/P1)
- [ ] None identified in this review.

- [ ] Recommended improvements (P2/P3)
- [ ] Make pending-action callback exception handling non-silent even if the handler fails or is missing (`src/Correlation/PendingAction.php`)
- [ ] Validate `bufferCap` against `maxFrameSize` to prevent misconfiguration-induced desync (`src/Protocol/Parser.php`)

# 5. Suggested Next Steps

1. Add a safe fallback logger/metric inside `PendingAction::invokeCallback()` when the callback exception handler throws or is null.
2. Validate parser configuration invariants (`bufferCap` vs `maxFrameSize`) and fail fast on invalid configs.
3. Add unit tests for pending-action callback failure observability.
4. Add unit tests for parser configuration validation (invalid buffer/frame relationships).

## Missing tests to add immediately

- `PendingAction` should record/log when a callback throws and the handler fails or is absent.
- `Parser` should throw a configuration exception when `bufferCap < maxFrameSize` (or when `bufferCap` is too small for the configured frame size).

## Hard invariants status

- [x] Core remains framework-agnostic (no `Illuminate*` in Core) - Laravel imports are isolated to `src/Laravel/*` (`src/Laravel/AmiClientServiceProvider.php:13`, `src/Laravel/Ami.php:8`, `src/Laravel/Commands/ListenCommand.php:8`).
- [x] Tick loop is fully non-blocking (no hidden blocking I/O, DNS, sleep, file I/O) - async connect, non-blocking sockets, and stream-select driven I/O (`src/Transport/TcpTransport.php:82-110`, `src/Transport/TcpTransport.php:200-238`, `src/Transport/Reactor.php:51-116`).
- [x] No synthetic success when responses are missing (correlation correctness) - missing-response paths fail unless the strategy explicitly allows event-only completion (`src/Correlation/CorrelationRegistry.php:139-155`).
- [x] Multi-server fairness: no node starvation under capped per-tick budgets - per-tick connection budget + rotating reconnect cursor (`src/Cluster/AmiClientManager.php:233-250`).
- [x] No cross-node ActionID contamination - per-server generator and per-client correlation registry (`src/Correlation/ActionIdGenerator.php:8-62`, `src/Cluster/AmiClientManager.php:416-423`).
- [x] Memory is bounded in all buffers/queues with enforced caps - write buffer, parser cap, event queue cap, pending action cap (`src/Transport/WriteBuffer.php:17-41`, `src/Protocol/Parser.php:49-55`, `src/Core/EventQueue.php:24-55`, `src/Correlation/CorrelationRegistry.php:61-66`).
- [x] Pending actions are deterministically failed on disconnect/timeout - timeout sweep + fail-all on close (`src/Correlation/CorrelationRegistry.php:163-186`, `src/Core/AmiClient.php:381-384`, `src/Core/AmiClient.php:797-805`).
- [x] Listener exceptions cannot break dispatch loops - listener isolation with try/catch in client and manager dispatch (`src/Core/AmiClient.php:517-540`, `src/Cluster/AmiClientManager.php:296-323`).
- [x] Reconnect storms cannot monopolize tick time - per-tick caps + circuit/timeout controls (`src/Health/ConnectionManager.php:299-349`, `src/Cluster/AmiClientManager.php:233-250`).
- [x] Queue drops are observable (logged or counted) - drop counters/metrics and log on drop (`src/Core/EventQueue.php:46-53`, `src/Core/AmiClient.php:704-717`, `src/Correlation/CorrelationRegistry.php:127-136`).

## Production readiness classification

- **88% -> Nearly Ready**
- **Primary gap to 100%:** Make callback-exception reporting non-silent and harden parser configuration validation to avoid avoidable desync under large-frame configs.
