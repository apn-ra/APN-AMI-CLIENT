# Production-Readiness Review of `src/` (apn/ami-client)

## 1. Executive Summary
- **Verdict: nearly ready (not production-ready yet)** for 24/7 dialer workloads.
- **Top strength 1:** per-node isolation and fair reconnect scheduling in manager loop (`src/Cluster/AmiClientManager.php:194`, `src/Cluster/AmiClientManager.php:203`, `src/Cluster/AmiClientManager.php:205`).
- **Top strength 2:** bounded memory controls exist across parser/write/pending/event paths (`src/Protocol/Parser.php:50`, `src/Transport/WriteBuffer.php:32`, `src/Correlation/CorrelationRegistry.php:47`, `src/Core/EventQueue.php:40`).
- **Top strength 3:** correlation rejects missing-response completion by default (no synthetic success except explicit event-only strategies) (`src/Correlation/CorrelationRegistry.php:114`, `src/Correlation/CorrelationRegistry.php:117`, `src/Correlation/CorrelationRegistry.php:124`).
- **Top blocker 1 (P1):** tick path is not fully non-blocking due hostname DNS in connect and hidden blocking wait in `close()` (`src/Transport/TcpTransport.php:47`, `src/Core/AmiClient.php:138`).
- **Top blocker 2 (P1):** action is registered before send; transport backpressure throws without unregister/fail, leaving orphaned pending actions (`src/Core/AmiClient.php:176`, `src/Core/AmiClient.php:209`, `src/Core/AmiClient.php:223`).
- **Top blocker 3 (P1):** pending-action callbacks are not isolated; callback exceptions can tear down processing loop/connection (`src/Correlation/PendingAction.php:117`, `src/Core/AmiClient.php:296`, `src/Core/AmiClient.php:301`).
- **Top blocker 4 (P2):** manager-created clients use `NullMetricsCollector`, so drop/backpressure/reconnect counters are effectively disabled unless custom wiring is added (`src/Cluster/AmiClientManager.php:353`, `src/Core/EventQueue.php:44`, `src/Core/AmiClient.php:217`).
- **Top blocker 5 (P2):** queue capacity is not validated; `capacity <= 0` can cause runtime dequeue errors under load (`src/Core/EventQueue.php:40`, `src/Core/EventQueue.php:41`).
- **Invariants compliance:** 8/10 pass, 2/10 fail. Failed invariants: non-blocking tick loop; listener/dispatch-loop exception isolation (pending callbacks).

## 2. Scorecard (0-5)
- Architecture/Boundaries: **4/5**
- Non-blocking I/O correctness: **2/5**
- Parser robustness: **4/5**
- Correlation correctness: **3/5**
- Event backpressure: **4/5**
- Reconnect/Health resilience: **4/5**
- Logging/Security: **3/5**
- Failure semantics integrity: **3/5**
- Testability/Extensibility: **3/5**

## 3. Findings (Evidence-Based)

### Finding 1
- **Severity:** P1 high
- **Location:** `src/Transport/TcpTransport.php:47`, `src/Core/AmiClient.php:138`
- **Why it matters (dialer impact):** 24/7 loops require strict non-blocking behavior. Hostname DNS resolution can block during connect, and `close()` adds hidden `stream_select` wait (`tick(10)`), which can stall hot paths and reduce throughput under churn.
- **Concrete fix (code-level direction):**
  - Pre-resolve hostnames at bootstrap (or require IP endpoints in production mode) and reuse resolved addresses.
  - Remove blocking flush attempt from `close()`; make graceful logoff optional and non-blocking (enqueue logoff, rely on normal tick for flush, then close next tick).

### Finding 2
- **Severity:** P1 high
- **Location:** `src/Core/AmiClient.php:176`, `src/Core/AmiClient.php:209`, `src/Core/AmiClient.php:223`
- **Why it matters (dialer impact):** on write-buffer backpressure, action state remains registered even though send failed; this creates ghost pending entries, false timeout noise, and eventual registry saturation.
- **Concrete fix (code-level direction):**
  - In `sendInternal`, if `transport->send()` throws, immediately fail/remove the just-registered action from correlation (add `cancel(actionId, exception)` API or return registration token for deterministic rollback).

### Finding 3
- **Severity:** P1 high
- **Location:** `src/Correlation/PendingAction.php:117`, `src/Core/AmiClient.php:292`, `src/Core/AmiClient.php:296`, `src/Core/AmiClient.php:301`
- **Why it matters (dialer impact):** user callback exceptions from pending completion propagate into protocol-processing loop, causing connection close and broad collateral failure.
- **Concrete fix (code-level direction):**
  - Wrap callback invocation in `PendingAction::notify()` with `try/catch` and surface via logger/metrics, without propagating into parser loop.

### Finding 4
- **Severity:** P2 medium
- **Location:** `src/Cluster/AmiClientManager.php:353`, `src/Core/EventQueue.php:44`, `src/Core/AmiClient.php:217`, `src/Health/ConnectionManager.php:321`
- **Why it matters (dialer impact):** metrics increments are implemented but manager wiring omits metrics collector injection, making drop/backpressure/reconnect observability incomplete in default deployment.
- **Concrete fix (code-level direction):**
  - Add `MetricsCollectorInterface` injection into `AmiClientManager` and propagate to `AmiClient`, `EventQueue`, and `ConnectionManager` in `createClient()`.

### Finding 5
- **Severity:** P2 medium
- **Location:** `src/Core/EventQueue.php:24`, `src/Core/EventQueue.php:40`, `src/Core/EventQueue.php:41`
- **Why it matters (dialer impact):** invalid capacity can trigger runtime exceptions (`dequeue` on empty), causing avoidable worker instability.
- **Concrete fix (code-level direction):**
  - Validate constructor input (`capacity >= 1`) and throw a typed config exception early.

### Finding 6
- **Severity:** P3 low
- **Location:** `src/Core/AmiClient.php:135`, `src/Core/AmiClient.php:139`
- **Why it matters (dialer impact):** swallowed exceptions during logoff hide useful diagnostics when shutdown behavior is abnormal.
- **Concrete fix (code-level direction):**
  - Log at debug/warn with structured context (`server_key`, reason) before suppressing.

## Invariants Validation (explicit)
- Core remains framework-agnostic (no `Illuminate\*` in Core): **PASS** (`src/Core/*` has no `Illuminate`; framework usage isolated to `src/Laravel/*`).
- Tick loop is fully non-blocking (no hidden blocking I/O, DNS, sleep, file I/O): **FAIL** (`src/Transport/TcpTransport.php:47`, `src/Core/AmiClient.php:138`).
- No synthetic success when responses are missing (correlation correctness): **PASS (guarded)** (`src/Correlation/CorrelationRegistry.php:114-125`; only allowed for explicit event-only strategy).
- Multi-server fairness: no node starvation under capped per-tick budgets: **PASS** (`src/Cluster/AmiClientManager.php:194-205`).
- No cross-node ActionID contamination: **PASS** (per-client registry + server-keyed ActionID generation; `src/Cluster/AmiClientManager.php:349-352`, `src/Correlation/CorrelationRegistry.php:21-24`).
- Memory is bounded in all buffers/queues with enforced caps: **PASS** (`src/Transport/WriteBuffer.php:32`, `src/Protocol/Parser.php:50`, `src/Correlation/CorrelationRegistry.php:47`, `src/Core/EventQueue.php:40`).
- Pending actions are deterministically failed on disconnect/timeout: **PASS (with caveat)** (`src/Correlation/CorrelationRegistry.php:135-158`, `src/Core/AmiClient.php:316`); caveat: send-backpressure rollback gap (Finding 2).
- Listener exceptions cannot break dispatch loops: **FAIL (pending callbacks path)** (`src/Correlation/PendingAction.php:117`).
- Reconnect storms cannot monopolize tick time: **PASS** (`src/Health/ConnectionManager.php:303-305`, `src/Cluster/AmiClientManager.php:189-201`).
- Queue drops are observable (logged or counted): **PASS** (`src/Core/EventQueue.php:42-45`, `src/Core/AmiClient.php:643-649`).

## 4. Production Readiness Checklist
- [ ] Must-fix blockers (P0/P1)
- [ ] Remove hidden blocking behavior in connect/close paths (Finding 1)
- [ ] Roll back/fail just-registered action when transport send fails (Finding 2)
- [ ] Isolate pending completion callbacks from core dispatch loop (Finding 3)
- [ ] Recommended improvements (P2/P3)
- [ ] Wire real metrics collector through manager/client stack (Finding 4)
- [ ] Validate queue capacity and related config boundaries at construction time (Finding 5)
- [ ] Replace silent swallow in shutdown/logoff with structured logging (Finding 6)

## 5. Suggested Next Steps
1. Fix Finding 2 first (rollback on send failure), because it directly affects correctness and can cascade into false timeout noise and pending saturation.
2. Remove `close()->tick(10)` and redesign graceful logoff as non-blocking stateful shutdown.
3. Decide production policy for endpoint resolution: enforce IPs or perform controlled async DNS/pre-resolution during bootstrap.
4. Add callback-isolation guard in `PendingAction::notify()` with logging/metrics.
5. Add constructor/config validators for `eventQueueCapacity`, `maxPendingActions`, `writeBufferLimit`, `maxFrameSize`.
6. Inject metrics collector through `AmiClientManager` and propagate labels uniformly.
7. Add tests for backpressure rollback correctness (send failure must not leave pending entry).
8. Add tests proving callback exceptions do not disconnect client or break dispatch loop.
9. Add tests for reconnect fairness under cluster-size > global connect cap.
10. Add tests for parser over-limit/desync behavior and guaranteed pending failure on disconnect.

### Missing tests to add immediately
- Backpressure during `sendInternal()` leaves no orphaned pending action.
- Exception thrown in pending completion callback is contained and logged.
- `close()` does not block tick loop.
- Cluster fairness test: with N nodes and cap M<N, reconnect attempts rotate across all nodes.

### Invariants currently violated
- Tick loop fully non-blocking.
- Listener/dispatch-loop exception isolation (pending callback path).
