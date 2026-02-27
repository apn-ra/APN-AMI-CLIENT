# Production Readiness Audit: `src/` (apntalk/ami-client)

## 1. Executive Summary
- **Verdict: Not Ready** for a 24/7 dialer workload in current form.
- **Top strength 1:** Core boundary hygiene is mostly intact: `Illuminate\*` usage is isolated to `src/Laravel/*`, with no Laravel imports in `src/Core/*`.
- **Top strength 2:** Memory bounding is implemented in key hot paths: parser buffer/frame caps and transport write buffer limits are present.
- **Top strength 3:** Correlation state is server-scoped by construction (per-client registry + server-keyed ActionID format), reducing cross-node contamination risk.
- **Top blocker 1 (P0):** Reconnect/connect path performs blocking socket connect inside the tick loop (`stream_socket_client(..., STREAM_CLIENT_CONNECT)`), violating non-blocking correctness.
- **Top blocker 2 (P1):** Cluster reconnect-throttle fairness is order-biased; later nodes can be starved under sustained reconnect pressure.
- **Top blocker 3 (P1):** Listener exceptions are not isolated; one bad listener can abort per-client event dispatch/tick processing.
- **Top blocker 4 (P1):** `send()` does not gate on health/connection state; actions can be enqueued while disconnected/authenticating, then timeout.
- **Top blocker 5 (P2):** Connection attempt failures in `processTick()` are swallowed without context, reducing operability under production incidents.

## 2. Scorecard (0-5)
- Architecture/Boundaries: **4/5**
- Non-blocking I/O correctness: **1/5**
- Parser robustness: **4/5**
- Correlation correctness: **3/5**
- Event backpressure: **3/5**
- Reconnect/Health resilience: **2/5**
- Logging/Security: **3/5**
- Testability/Extensibility: **3/5**

## 3. Findings (Evidence-Based)

### P0 blocker: Blocking connect call in the tick/reconnect path
- **Severity:** P0 blocker
- **Location:** `src/Transport/TcpTransport.php:46`, `src/Transport/TcpTransport.php:51`, `src/Core/AmiClient.php:290`, `src/Core/AmiClient.php:294`
- **Why it matters:** `stream_socket_client` is used with `STREAM_CLIENT_CONNECT`, which blocks up to `connectTimeout`. Because reconnect attempts are executed from `processTick()`, one bad network path can stall the entire event loop and delay all nodes/events.
- **Concrete fix:** Switch to non-blocking connect (`STREAM_CLIENT_ASYNC_CONNECT`), track connect-in-progress state, and complete connection via write-readiness/error checks in reactor ticks.

### P1 high: Cluster reconnect fairness can starve nodes
- **Severity:** P1 high
- **Location:** `src/Cluster/AmiClientManager.php:183`, `src/Cluster/AmiClientManager.php:185`, `src/Cluster/AmiClientManager.php:187`, `src/Cluster/AmiClientManager.php:189`
- **Why it matters:** Global `maxConnectAttemptsPerTick` is consumed in fixed array iteration order every tick. In a prolonged outage, early keys repeatedly consume budget and later nodes may not reconnect (starvation), violating fairness requirements.
- **Concrete fix:** Rotate iteration start index per tick (round-robin sweep), or maintain a reconnect queue/token-bucket per node to ensure eventual service for every disconnected node.

### P1 high: Listener exception isolation is missing
- **Severity:** P1 high
- **Location:** `src/Core/AmiClient.php:353`, `src/Core/AmiClient.php:358`, `src/Cluster/AmiClientManager.php:231`, `src/Cluster/AmiClientManager.php:237`
- **Why it matters:** Listener callbacks are invoked without try/catch. A single listener throw can abort event processing for that client tick and reduce delivery guarantees under load.
- **Concrete fix:** Wrap each listener call in isolated try/catch; log with `server_key`, `event_name`, listener identifier, and continue dispatching remaining listeners/events.

### P1 high: Actions can be accepted while not available
- **Severity:** P1 high
- **Location:** `src/Core/AmiClient.php:142`, `src/Core/AmiClient.php:181`, `src/Core/AmiClient.php:515`
- **Why it matters:** `send()` does not enforce transport/health availability. During disconnected/authenticating windows, actions may enter correlation and write buffers but never be transmitted, causing avoidable timeouts and false load/backpressure signals.
- **Concrete fix:** Reject sends when `!$transport->isConnected()` or status is not send-eligible, with typed exception (`ConnectionException`/`AmiException`) including status/server context.

### P2 medium: Connect failure is silently swallowed
- **Severity:** P2 medium
- **Location:** `src/Core/AmiClient.php:295`, `src/Core/AmiClient.php:296`
- **Why it matters:** Operational diagnosis is degraded when reconnect attempts fail repeatedly with no error context.
- **Concrete fix:** Log exception message and endpoint context (`server_key`, `host`, `port`, attempt count, next retry delay) before state transition.

### P2 medium: `readTimeout` option is configured but not enforced
- **Severity:** P2 medium
- **Location:** `src/Cluster/ClientOptions.php:14`, `src/Cluster/ClientOptions.php:38`, `src/Transport/TcpTransport.php:25`
- **Why it matters:** Public API suggests read-timeout control, but transport does not consume it. This mismatch can cause unsafe assumptions in production tuning/runbooks.
- **Concrete fix:** Either implement idle/read timeout enforcement in connection manager/transport, or remove/deprecate `readTimeout` from options until supported.

### P2 medium: Reconnect storm control exists but no circuit breaker behavior
- **Severity:** P2 medium
- **Location:** `src/Health/ConnectionManager.php:227`, `src/Health/ConnectionManager.php:234`, `src/Health/ConnectionManager.php:203`
- **Why it matters:** Backoff+jitter is present, but there is no open/half-open circuit policy or suppression after repeated hard failures; this can still produce persistent churn against dead endpoints.
- **Concrete fix:** Add circuit states with cooldown and probe windows; expose breaker metrics and transition logs.

### P2 medium: One `close()` path swallows errors without telemetry
- **Severity:** P2 medium
- **Location:** `src/Core/AmiClient.php:129`, `src/Core/AmiClient.php:130`
- **Why it matters:** Silent failures during logoff/teardown make shutdown diagnostics and incident reconstruction harder.
- **Concrete fix:** Catch typed exceptions where possible; at minimum log debug/warn context when logoff send fails during close.

### P3 low: Queue depth not consistently included in logs
- **Severity:** P3 low
- **Location:** `src/Core/Logger.php:67`, `src/Core/Logger.php:68`
- **Why it matters:** `server_key` and `action_id` are normalized, but `queue_depth` is not standardized, reducing comparability for backpressure incident analysis.
- **Concrete fix:** Standardize `queue_depth` in backpressure/event-drop related logs and optionally set null default in logger schema.

### Positive finding: Bounded memory protections in parser/transport
- **Severity:** P3 low (positive)
- **Location:** `src/Protocol/Parser.php:19`, `src/Protocol/Parser.php:47`, `src/Protocol/Parser.php:109`, `src/Transport/WriteBuffer.php:21`, `src/Transport/WriteBuffer.php:32`, `src/Transport/TcpTransport.php:87`
- **Why it matters:** Hard caps and enforced backpressure reduce OOM risk during bursts/malformed traffic.
- **Concrete fix:** Keep unchanged; add tests asserting caps under adversarial input.

### Positive finding: Server-scoped correlation and ActionID structure
- **Severity:** P3 low (positive)
- **Location:** `src/Cluster/AmiClientManager.php:319`, `src/Cluster/AmiClientManager.php:320`, `src/Correlation/ActionIdGenerator.php:31`, `src/Correlation/CorrelationRegistry.php:19`
- **Why it matters:** Per-client registry + server-prefixed ActionIDs materially reduces cross-node correlation contamination risk.
- **Concrete fix:** Keep unchanged; add explicit collision/isolation regression tests.

### Positive finding: Framework boundary mostly respected
- **Severity:** P3 low (positive)
- **Location:** `src/Laravel/AmiClientServiceProvider.php:13`, `src/Laravel/Ami.php:8`, `src/Laravel/Commands/ListenCommand.php:8`
- **Why it matters:** Laravel coupling is confined to adapter layer; Core remains framework-agnostic by imports.
- **Concrete fix:** Keep unchanged; enforce with CI static check forbidding `Illuminate\*` outside `src/Laravel/`.

## 4. Production Readiness Checklist
- [ ] Replace blocking connect with async non-blocking connect state machine (P0).
- [ ] Fix reconnect fairness to prevent node starvation under global connect-attempt cap (P1).
- [ ] Add listener exception isolation in both client and manager dispatch loops (P1).
- [ ] Gate `send()` by connection/health state and return typed actionable errors (P1).
- [ ] Add reconnect failure logging with contextual fields and retry metadata (P2).
- [ ] Align `readTimeout` config with implementation (implement or remove/deprecate) (P2).
- [ ] Add circuit breaker behavior above current backoff/jitter model (P2).
- [ ] Remove silent teardown swallowing or at least log swallow events (P2).
- [ ] Standardize queue-depth log context for observability consistency (P3).

## 5. Suggested Next Steps
1. Implement async connect in `TcpTransport` and reactor-driven connect completion.
2. Refactor `tickAll()` to fairness-safe reconnect scheduling (rotating cursor or per-node queue).
3. Wrap all listener invocations with isolated try/catch + structured logging.
4. Add `send()` preconditions and explicit typed failure for unavailable states.
5. Add reconnect telemetry fields (`attempt`, `next_retry_at`, endpoint, status transition).
6. Decide on `readTimeout` contract and enforce code/config consistency.
7. Introduce circuit breaker states in `ConnectionManager`.
8. Add tests: reconnect starvation regression for >N nodes with capped attempts.
9. Add tests: listener throws should not stop other listeners/events.
10. Add tests: non-blocking connect behavior under unreachable host and slow SYN path.

