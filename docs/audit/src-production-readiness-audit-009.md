# Production Readiness Audit 009: `src/`

## 1. Executive Summary
- **Production readiness verdict:** **Nearly Ready (84%)**.
- **Top strength #1:** Core boundaries are clean; Laravel dependencies are isolated to `src/Laravel/*` and not imported in Core/Protocol/Transport/Correlation/Cluster/Health (evidence: no `Illuminate\\` matches in those folders).
- **Top strength #2:** Correlation lifecycle is deterministic and bounded: pending-action cap, timeout sweep, and disconnect fail-all are implemented (`src/Correlation/CorrelationRegistry.php:63`, `src/Correlation/CorrelationRegistry.php:164`, `src/Core/AmiClient.php:393`, `src/Core/AmiClient.php:816`, `src/Core/AmiClient.php:830`).
- **Top strength #3:** Multi-node reconnect fairness and storm control are implemented using global per-tick connect budget + reconnect cursor rotation (`src/Cluster/AmiClientManager.php:241`, `src/Cluster/AmiClientManager.php:245`, `src/Cluster/AmiClientManager.php:252`, `src/Cluster/AmiClientManager.php:255`).
- **Top blocker #1 (P1):** Cadence controls are effectively disabled, creating a hot-spin worker loop under `ami:listen` (tight `while (true)` + timeout clamped to `0`) (`src/Laravel/Commands/ListenCommand.php:55`, `src/Cluster/AmiClientManager.php:275`, `src/Cluster/AmiClientManager.php:284`, `src/Transport/Reactor.php:221`, `src/Transport/Reactor.php:223`, `src/Transport/TcpTransport.php:372`, `src/Transport/TcpTransport.php:374`).
- **Top blocker #2 (P2):** `tick(timeoutMs)` API semantics are misleading because timeout is ignored end-to-end; caller-supplied wait windows never apply (`src/Core/Contracts/TransportInterface.php:37`, `src/Cluster/AmiClientManager.php:200`, `src/Cluster/AmiClientManager.php:218`, `src/Cluster/AmiClientManager.php:284`).
- **Top blocker #3 (P2):** Hostname support in `ConfigLoader` path is incomplete: loader cannot inject a hostname resolver, so non-IP hosts fail even when `enforce_ip_endpoints` is disabled (`src/Cluster/ConfigLoader.php:30`, `src/Cluster/AmiClientManager.php:513`, `src/Cluster/AmiClientManager.php:514`).
- **Top blocker #4 (P2):** Critical numeric options are not range-validated at load time; invalid values can silently destabilize behavior (e.g., reconnect attempts budget, timeouts, queue limits) (`src/Cluster/ClientOptions.php:49`, `src/Cluster/ClientOptions.php:73`, `src/Cluster/AmiClientManager.php:241`, `src/Health/ConnectionManager.php:313`).
- **Top blocker #5 (P3):** `TcpTransport::$enforceIpEndpoints` does not change behavior in `open()` (non-IP host is always rejected), which weakens configuration clarity (`src/Transport/TcpTransport.php:42`, `src/Transport/TcpTransport.php:65`, `src/Transport/TcpTransport.php:67`, `src/Transport/TcpTransport.php:70`).
- **Hard invariants compliance statement:** 10/10 invariants pass in current `src/` implementation (see checklist below), but the cadence/API issues still reduce operational readiness for long-running dialer workers.

## 2. Scorecard (0–5)
- Architecture/Boundaries: **4.8/5**
- Non-blocking I/O correctness: **4.3/5**
- Parser robustness: **4.7/5**
- Correlation correctness: **4.8/5**
- Event backpressure: **4.6/5**
- Reconnect/Health resilience: **4.4/5**
- Logging/Security: **4.5/5**
- Failure semantics integrity: **4.7/5**
- Testability/Extensibility: **3.6/5**

## 3. Findings (Evidence-Based)

### Finding 1
- **Severity:** P1 high
- **Location:** `src/Laravel/Commands/ListenCommand.php:55`, `src/Cluster/AmiClientManager.php:275`, `src/Cluster/AmiClientManager.php:284`, `src/Transport/Reactor.php:221`, `src/Transport/Reactor.php:223`, `src/Transport/TcpTransport.php:372`, `src/Transport/TcpTransport.php:374`
- **Why it matters (dialer impact):** The worker loop runs continuously without any effective wait window, which can produce sustained CPU hot-spin in 24/7 deployments and reduce headroom during bursts/reconnect storms.
- **Concrete fix (code-level direction):** Introduce an explicit runtime cadence strategy in the worker layer (not inside protocol parsing): either honor bounded timeout in the reactor path for CLI worker mode or add a dedicated pacing hook with clear production profile defaults and tests.

### Finding 2
- **Severity:** P2 medium
- **Location:** `src/Core/Contracts/TransportInterface.php:37`, `src/Core/Contracts/AmiClientInterface.php:52`, `src/Cluster/AmiClientManager.php:200`, `src/Cluster/AmiClientManager.php:218`, `src/Cluster/AmiClientManager.php:284`
- **Why it matters (dialer impact):** Public API exposes `timeoutMs` but runtime unconditionally discards it, increasing operator confusion and making cadence tuning/expectations incorrect.
- **Concrete fix (code-level direction):** Either (a) honor `timeoutMs` where safe and documented, or (b) remove/deprecate timeout parameters and document always-non-blocking semantics consistently across API, manager, and command layer.

### Finding 3
- **Severity:** P2 medium
- **Location:** `src/Cluster/ConfigLoader.php:30`, `src/Cluster/AmiClientManager.php:513`, `src/Cluster/AmiClientManager.php:514`
- **Why it matters (dialer impact):** Production configs that use hostnames cannot be bootstrapped via `ConfigLoader` unless callers bypass loader and manually inject a resolver, creating a failure mode at startup.
- **Concrete fix (code-level direction):** Extend `ConfigLoader::load()` to accept/inject a hostname resolver callback (or pre-resolve hostnames before manager construction) and add integration tests for hostname bootstrapping.

### Finding 4
- **Severity:** P2 medium
- **Location:** `src/Cluster/ClientOptions.php:49`, `src/Cluster/ClientOptions.php:73`, `src/Cluster/AmiClientManager.php:241`, `src/Health/ConnectionManager.php:313`
- **Why it matters (dialer impact):** Missing range checks allow invalid numeric settings to silently alter behavior (for example, reconnect budget <= 0 preventing attempts, or pathological timeout values), reducing predictability in production.
- **Concrete fix (code-level direction):** Add strict option validation with typed `InvalidConfigurationException` for all critical bounds (timeouts > 0, capacities >= 1, reconnect attempts >= 1, size limits sane).

### Finding 5
- **Severity:** P3 low
- **Location:** `src/Transport/TcpTransport.php:42`, `src/Transport/TcpTransport.php:65`, `src/Transport/TcpTransport.php:67`, `src/Transport/TcpTransport.php:70`
- **Why it matters (dialer impact):** `enforceIpEndpoints` appears configurable but transport rejects non-IP endpoints regardless, which can mislead integrators and create configuration ambiguity.
- **Concrete fix (code-level direction):** Either remove this transport-level flag and enforce IP-only behavior explicitly everywhere, or make the conditional behavior real and covered by tests.

### Hard Invariants Validation
- **Core remains framework-agnostic (no `Illuminate\*` in Core):** **PASS**. `Illuminate\*` imports are in `src/Laravel/*` only (`src/Laravel/AmiClientServiceProvider.php:13`, `src/Laravel/Ami.php:8`, `src/Laravel/Commands/ListenCommand.php:9`).
- **Tick loop is fully non-blocking (no hidden blocking I/O, DNS, sleep, file I/O):** **PASS**. Tick paths clamp runtime wait to non-blocking and use non-blocking stream operations (`src/Cluster/AmiClientManager.php:284`, `src/Transport/Reactor.php:223`, `src/Transport/TcpTransport.php:374`, `src/Transport/TcpTransport.php:109`).
- **No synthetic success when responses are missing (unless strategy requires it):** **PASS**. Missing response fails with typed exception unless strategy explicitly implements event-only marker (`src/Correlation/CorrelationRegistry.php:143`, `src/Correlation/CorrelationRegistry.php:146`, `src/Core/Contracts/EventOnlyCompletionStrategyInterface.php:11`).
- **Multi-server fairness: no node starvation under capped per-tick budgets:** **PASS**. Cursor-based rotation and connect budget are implemented (`src/Cluster/AmiClientManager.php:245`, `src/Cluster/AmiClientManager.php:251`, `src/Cluster/AmiClientManager.php:255`).
- **No cross-node ActionID contamination:** **PASS**. Action IDs include per-server prefix and per-client generators (`src/Correlation/ActionIdGenerator.php:41`, `src/Cluster/AmiClientManager.php:440`).
- **Memory is bounded in all buffers/queues with enforced caps:** **PASS**. Event queue, write buffer, parser cap, pending cap are bounded (`src/Core/EventQueue.php:48`, `src/Transport/WriteBuffer.php:32`, `src/Protocol/Parser.php:65`, `src/Correlation/CorrelationRegistry.php:63`).
- **Pending actions are deterministically failed on disconnect/timeout:** **PASS**. Timeout sweep + force/final close fail-all behavior is wired (`src/Correlation/CorrelationRegistry.php:170`, `src/Core/AmiClient.php:393`, `src/Core/AmiClient.php:830`).
- **Listener exceptions cannot break dispatch loops:** **PASS**. Listener/callback exceptions are isolated in try/catch with logging (`src/Core/AmiClient.php:529`, `src/Core/AmiClient.php:542`, `src/Cluster/AmiClientManager.php:318`, `src/Correlation/PendingAction.php:136`).
- **Reconnect storms cannot monopolize tick time:** **PASS**. Reconnect attempts are bounded per tick and per manager cycle (`src/Health/ConnectionManager.php:313`, `src/Cluster/AmiClientManager.php:241`).
- **Queue drops are observable (logged or counted):** **PASS**. Drops are counted and periodically logged (`src/Core/EventQueue.php:52`, `src/Core/AmiClient.php:738`, `src/Correlation/CorrelationRegistry.php:132`).

## 4. Production Readiness Checklist
- [ ] Must-fix blockers (P0/P1)
- [ ] Eliminate hot-spin behavior by implementing explicit worker cadence semantics for `ami:listen` (without undermining non-blocking safety).

- [ ] Recommended improvements (P2/P3)
- [ ] Align timeout API contract with runtime behavior (honor it or remove it consistently).
- [ ] Add hostname-resolver injection support in `ConfigLoader` path for non-IP endpoints.
- [ ] Add strict validation for `ClientOptions` numeric bounds and fail fast on invalid config.
- [ ] Resolve `enforceIpEndpoints` transport-level ambiguity (remove or implement behavior).

## 5. Suggested Next Steps
1. Fix worker cadence first: define how `ami:listen` should pace CPU in production, then implement + test that behavior.
2. Resolve timeout contract drift by making `timeoutMs` semantics explicit and consistent across interfaces and runtime.
3. Add comprehensive `ClientOptions` validation with typed exceptions and table-driven tests for invalid values.
4. Extend `ConfigLoader` to support hostname resolver injection and add hostname bootstrapping integration tests.
5. Tighten endpoint policy design by removing/clarifying transport-level `enforceIpEndpoints` semantics.
6. Add regression tests for cadence behavior under idle loops, reconnect storms, and high event rates.
7. Add explicit invariant tests (one per hard invariant) to prevent future regressions.
8. Re-run `vendor/bin/phpunit` in an environment where loopback ephemeral bind (`127.0.0.1:0`) is available; current run failed with socket bind errors in transport/integration tests.

### Missing tests to add immediately
- Cadence/CPU safety test for idle `ami:listen` loop behavior.
- API-contract test asserting timeout behavior consistency (`tick(timeoutMs)` path).
- Config validation test matrix for invalid numeric options (negative/zero/out-of-range).
- ConfigLoader hostname-resolution test path with injected resolver.

### Invariants currently violated
- None of the 10 hard invariants are currently violated based on `src/` evidence.

## Production Readiness Classification
- **Percentage:** **84%**
- **Classification:** **Nearly Ready**
- **What prevents 100%:** worker cadence hot-spin risk, timeout API contract drift, and configuration hardening/integration gaps (resolver injection + option validation).
