# 1. Executive Summary

- **Production readiness verdict:** **Nearly Ready** at **84%**.
- **Top strength 1:** Core architecture remains framework-agnostic in non-Laravel layers (`NO_MATCH` for `Illuminate\\` under `src/Core`, `src/Protocol`, `src/Transport`, `src/Correlation`, `src/Cluster`, `src/Exceptions`; Laravel imports are isolated in `src/Laravel/*`).
- **Top strength 2:** Bounded memory controls are implemented across key hot paths: write buffer cap (`src/Transport/WriteBuffer.php:21`, `src/Transport/WriteBuffer.php:32`), parser caps (`src/Protocol/Parser.php:23`, `src/Protocol/Parser.php:50`, `src/Protocol/Parser.php:112`), event queue cap (`src/Core/EventQueue.php:25`, `src/Core/EventQueue.php:48`), pending action cap (`src/Correlation/CorrelationRegistry.php:45`, `src/Correlation/CorrelationRegistry.php:63`).
- **Top strength 3:** Correlation lifecycle is deterministic for disconnect/timeout: timeout sweep (`src/Correlation/CorrelationRegistry.php:163`), disconnect fail-all (`src/Correlation/CorrelationRegistry.php:181`), and client force-close paths always fail pending actions (`src/Core/AmiClient.php:383`, `src/Core/AmiClient.php:791`, `src/Core/AmiClient.php:805`).
- **Top blocker 1 (P1):** Socket/selector error suppression (`@stream_select`, `@fread`, `@stream_socket_client`, etc.) masks transport failures and removes diagnosability (`src/Transport/TcpTransport.php:61`, `src/Transport/TcpTransport.php:171`, `src/Transport/TcpTransport.php:202`, `src/Transport/Reactor.php:76`).
- **Top blocker 2 (P1):** Logger can throw `JsonException` from `JSON_THROW_ON_ERROR`, which can escape event-listener exception paths and break dispatch loops (`src/Core/Logger.php:79`, invoked inside listener catch blocks at `src/Core/AmiClient.php:522`, `src/Core/AmiClient.php:535`).
- **Top blocker 3 (P2):** Regex suppression in redaction (`@preg_match`, `@preg_replace`) can silently disable redaction patterns and hide config defects (`src/Core/SecretRedactor.php:106`, `src/Core/SecretRedactor.php:118`).
- **Top blocker 4 (P2):** Optional hostname resolution uses `gethostbyname()` during manager bootstrap, introducing blocking DNS behavior outside reactor/tick path (`src/Cluster/AmiClientManager.php:462`).
- **Top blocker 5 (P2):** Library-level signal handler hard-exits process (`exit(0)`), which is unsafe in embedded/worker runtimes (`src/Cluster/AmiClientManager.php:503`).
- **Invariant compliance statement:** 9/10 hard invariants pass; **failed invariant:** “Listener exceptions cannot break dispatch loops” due to uncaught logger serialization failures.

# 2. Scorecard (0-5)

- Architecture/Boundaries: **5/5**
- Non-blocking I/O correctness: **3/5**
- Parser robustness: **4/5**
- Correlation correctness: **4/5**
- Event backpressure: **4/5**
- Reconnect/Health resilience: **4/5**
- Logging/Security: **2/5**
- Failure semantics integrity: **3/5**
- Testability/Extensibility: **4/5**

# 3. Findings (Evidence-Based)

## F-001
- **Severity:** P1 high
- **Location:** `src/Transport/TcpTransport.php:61`, `src/Transport/TcpTransport.php:171`, `src/Transport/TcpTransport.php:202`, `src/Transport/TcpTransport.php:315`, `src/Transport/TcpTransport.php:320`, `src/Transport/Reactor.php:76`
- **Why it matters (dialer impact):** Error suppression on socket and selector operations can hide partial network failures, leading to silent stalls, delayed reconnect decisions, and low observability during live incidents.
- **Concrete fix (code-level direction):** Remove `@` operators on transport/selector calls, capture native error details (`error_get_last()`, socket errno where available), and emit structured warnings/metrics before controlled close/retry.

## F-002
- **Severity:** P1 high
- **Location:** `src/Core/Logger.php:79`, `src/Core/AmiClient.php:519`, `src/Core/AmiClient.php:532`
- **Why it matters (dialer impact):** Listener exceptions are caught, but if logging that exception throws (`JSON_THROW_ON_ERROR`), the catch block itself can fail and terminate event dispatch for the tick, violating listener isolation under malformed payload contexts.
- **Concrete fix (code-level direction):** Make logger fail-safe: wrap encoding/output in internal `try/catch`, fallback to minimal plain-text line on serialization failure, and never throw from `Logger::log()`.

## F-003
- **Severity:** P2 medium
- **Location:** `src/Core/SecretRedactor.php:106`, `src/Core/SecretRedactor.php:118`
- **Why it matters (dialer impact):** Invalid custom redaction regexes fail silently, creating false confidence and potential secret leakage in production logs.
- **Concrete fix (code-level direction):** Validate regexes at construction time and throw `InvalidConfigurationException` for invalid patterns; remove suppression operators and log/metric failed pattern evaluations.

## F-004
- **Severity:** P2 medium
- **Location:** `src/Cluster/AmiClientManager.php:462`
- **Why it matters (dialer impact):** `gethostbyname()` is blocking; large cluster bootstrap or DNS latency can pause startup/reload workflows and delay failover readiness.
- **Concrete fix (code-level direction):** Keep IP-only policy strict by default (already true), and for hostname mode require pre-resolved addresses via config loader or explicit resolver injection outside runtime-critical path.

## F-005
- **Severity:** P2 medium
- **Location:** `src/Cluster/AmiClientManager.php:503`
- **Why it matters (dialer impact):** Calling `exit(0)` from library code can terminate shared worker processes unexpectedly, impacting unrelated workloads.
- **Concrete fix (code-level direction):** Replace `exit(0)` with callback/event hook so host application decides termination semantics.

# 4. Production Readiness Checklist

- [ ] Must-fix blockers (P0/P1)
- [ ] Remove transport/reactor error suppression and add structured error emission (`src/Transport/TcpTransport.php`, `src/Transport/Reactor.php`)
- [ ] Make logger non-throwing under serialization/output failures (`src/Core/Logger.php`)
- [ ] Add regression tests proving listener isolation even when logger encoding fails (`tests/Unit/Core/AmiClientTest.php`, `tests/Unit/Core/LoggerTest.php`)

- [ ] Recommended improvements (P2/P3)
- [ ] Replace regex suppression with validated redaction pattern handling (`src/Core/SecretRedactor.php`)
- [ ] Remove hard process exit from signal handler (`src/Cluster/AmiClientManager.php`)
- [ ] Keep hostname resolution out of runtime manager path when hostname mode is enabled (`src/Cluster/AmiClientManager.php`)

# 5. Suggested Next Steps

1. Remove all `@`-suppressed transport/reactor calls; propagate and log typed transport failures with context (`server_key`, `action_id`, `queue_depth`).
2. Harden `Logger::log()` to never throw; add fallback serialization path.
3. Add targeted tests for logger-failure isolation in event listener catch paths.
4. Validate redaction regex patterns on boot and fail fast on invalid patterns.
5. Refactor signal handler to delegate shutdown decision to application layer.
6. Restrict hostname resolution to pre-bootstrap configuration stage only.
7. Add integration test for selector failure handling under closed/errored sockets.
8. Add integration test asserting reconnect loop remains observable (metrics/logs) under repeated socket errors.

## Missing tests to add immediately

- `Logger` serialization-failure test using malformed UTF-8 context to prove `Logger::log()` does not throw.
- `AmiClient` event listener isolation test where logger itself fails during listener-exception reporting.
- `SecretRedactor` invalid-regex configuration test asserting deterministic validation failure.
- `TcpTransport`/`Reactor` test asserting selector/socket errors are logged/metriced (not silently ignored).

## Hard invariants status

- [x] Core remains framework-agnostic (no `Illuminate*` in Core) - validated via scoped search (`NO_MATCH`) and Laravel usage confined to `src/Laravel/*`.
- [x] Tick loop is fully non-blocking (no hidden blocking I/O, DNS, sleep, file I/O) - tick path uses non-blocking stream select/read/write (`src/Transport/TcpTransport.php:154`, `src/Transport/Reactor.php:38`); DNS appears only in bootstrap path (`src/Cluster/AmiClientManager.php:462`).
- [x] No synthetic success when responses are missing (correlation correctness) - synthetic success gated only for strategies explicitly marked `EventOnlyCompletionStrategyInterface` (`src/Correlation/CorrelationRegistry.php:142`); no such strategy implementation exists in `src/`.
- [x] Multi-server fairness: no node starvation under capped per-tick budgets - reconnect cursor rotation and per-tick global cap are implemented (`src/Cluster/AmiClientManager.php:218`, `src/Cluster/AmiClientManager.php:228`).
- [x] No cross-node ActionID contamination - per-client generator seeded by server key and instance (`src/Cluster/AmiClientManager.php:395`, `src/Correlation/ActionIdGenerator.php:41`).
- [x] Memory is bounded in all buffers/queues with enforced caps - write/parser/event/pending caps enforced (`src/Transport/WriteBuffer.php:32`, `src/Protocol/Parser.php:50`, `src/Core/EventQueue.php:48`, `src/Correlation/CorrelationRegistry.php:63`).
- [x] Pending actions are deterministically failed on disconnect/timeout - timeout sweep and fail-all on close/force-close (`src/Correlation/CorrelationRegistry.php:163`, `src/Core/AmiClient.php:383`, `src/Core/AmiClient.php:805`).
- [ ] Listener exceptions cannot break dispatch loops - **failed** because logging inside catch blocks can throw (`src/Core/Logger.php:79`, `src/Core/AmiClient.php:522`, `src/Core/AmiClient.php:535`).
- [x] Reconnect storms cannot monopolize tick time - capped connect attempts and reconnect throttling/circuit controls (`src/Cluster/AmiClientManager.php:214`, `src/Health/ConnectionManager.php:312`, `src/Health/CircuitBreaker.php:68`).
- [x] Queue drops are observable (logged or counted) - drop counters/metrics/logging are present (`src/Core/EventQueue.php:50`, `src/Core/EventQueue.php:52`, `src/Core/AmiClient.php:711`, `src/Correlation/CorrelationRegistry.php:131`).

## Production readiness classification

- **84% -> Nearly Ready**
- **Primary gap to 100%:** fail-safe error-path hardening (transport error visibility + non-throwing logging), plus removal of silent suppression in security-sensitive redaction.
