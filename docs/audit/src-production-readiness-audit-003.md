### 1. Executive Summary (5–10 bullets)
- Verdict: Not Ready (production-readiness estimate 68%).
- Strength: Non-blocking transport design with async connect, non-blocking sockets, and capped per-tick read/write budgets. (src/Transport/TcpTransport.php:55-177)
- Strength: Correlation lifecycle is explicit, with timeout sweeps and deterministic fail-all on disconnect. (src/Correlation/CorrelationRegistry.php:143-167, src/Core/AmiClient.php:764-786)
- Strength: Bounded queues/buffers with observable event drops and backpressure handling. (src/Core/EventQueue.php:24-55, src/Transport/WriteBuffer.php:20-41, src/Core/AmiClient.php:201-268)
- Blocker: Tick loop is not strictly non-blocking because DNS resolution can block when hostnames are allowed (default), violating the invariant. (src/Transport/TcpTransport.php:41-61, src/Cluster/ClientOptions.php:37)
- Blocker: Tick loop can block on stream_select when timeoutMs > 0 (API allows blocking), violating the invariant as written. (src/Core/AmiClient.php:301-307, src/Transport/TcpTransport.php:147-165, src/Transport/Reactor.php:37-75)
- Risk: Heartbeat failure sets DISCONNECTED without forcing reconnect, allowing a stale connection to continue without recovery. (src/Health/ConnectionManager.php:134-142, src/Core/AmiClient.php:455-462)
- Risk: Action event collection silently drops events beyond maxMessages without any logging/metrics. (src/Correlation/CorrelationRegistry.php:110-117)
- Invariants: Core framework-agnostic PASS; Tick loop fully non-blocking FAIL; No synthetic success when responses missing PASS (except explicit EventOnly strategies); Multi-server fairness PASS; No cross-node ActionID contamination PASS; Memory bounded PASS; Pending actions fail on disconnect/timeout PASS; Listener exceptions isolated PASS; Reconnect storms bounded PASS; Queue drops observable PASS (event queue), PARTIAL for correlation event drops.

### 2. Scorecard (0–5 each)
- Architecture/Boundaries: 4
- Non-blocking I/O correctness: 2
- Parser robustness: 4
- Correlation correctness: 4
- Event backpressure: 3
- Reconnect/Health resilience: 3
- Logging/Security: 4
- Failure semantics integrity: 4
- Testability/Extensibility: 3

### 3. Findings (Evidence-Based)
- Severity: P1 high
  Location: src/Transport/TcpTransport.php:41-61, src/Cluster/ClientOptions.php:37
  Why it matters (dialer impact): Hostname DNS resolution can block in `stream_socket_client` even with async connect, stalling the tick loop under load or during DNS outages. This violates the non-blocking invariant for 24/7 dialer workloads.
  Concrete fix (code-level direction): Default to IP-only endpoints or pre-resolve DNS outside the tick loop. Enforce `enforceIpEndpoints = true` by default or add a non-blocking resolver and pass IPs to TcpTransport.

- Severity: P1 high
  Location: src/Core/AmiClient.php:301-307, src/Transport/TcpTransport.php:147-165, src/Transport/Reactor.php:37-75
  Why it matters (dialer impact): `stream_select` blocks when `timeoutMs > 0`, which is allowed by public API. This can starve other per-tick budgets and violates the “fully non-blocking tick loop” invariant.
  Concrete fix (code-level direction): Enforce `timeoutMs = 0` in internal tick loops or document and enforce a non-blocking reactor ownership model where blocking waits are external to the client. Consider splitting `tick()` into `poll()` (non-blocking) and `wait()` (blocking) APIs.

- Severity: P2 medium
  Location: src/Health/ConnectionManager.php:134-142, src/Core/AmiClient.php:455-462
  Why it matters (dialer impact): Heartbeat failures set status to DISCONNECTED but the transport remains open and the next tick promotes status back to CONNECTED. This can mask half-open connections and delay recovery under dialer load.
  Concrete fix (code-level direction): On max heartbeat failures, force-close the transport and trigger reconnect backoff (e.g., call `forceClose()` in AmiClient, or raise a flag consumed in `processTick()` to close the socket deterministically).

- Severity: P2 medium
  Location: src/Correlation/CorrelationRegistry.php:110-117
  Why it matters (dialer impact): When `maxMessages` is reached, additional events are dropped silently. This can truncate multi-event responses without any visibility, leading to partial data and difficult debugging.
  Concrete fix (code-level direction): Add metrics/logging for dropped correlation events and optionally fail the action when the cap is exceeded (or expose a configurable drop policy).

### 4. Production Readiness Checklist
- [ ] Must-fix blockers (P0/P1)
- [ ] Recommended improvements (P2/P3)

### 5. Suggested Next Steps
Prioritized plan (max 10 items)
1. Enforce non-blocking DNS behavior: require IP endpoints by default or implement pre-resolve outside tick loops.
2. Split blocking vs non-blocking tick APIs and make the production path strictly non-blocking.
3. On heartbeat failure threshold, deterministically close and reconnect the transport.
4. Add observability for correlation event drops (metrics + log context).
5. Document the expected reactor ownership model and timeouts for dialer operators.

Missing tests to add immediately
1. DNS resolution blocking guard (verify IP-only enforcement or pre-resolve path).
2. Tick loop non-blocking contract (timeoutMs=0 behavior, no blocking calls).
3. Heartbeat failure triggers reconnect and pending actions fail deterministically.
4. Correlation event cap behavior (metrics/logging and failure policy).

Invariants currently violated
- Tick loop fully non-blocking (DNS resolution and stream_select timeout behavior).
