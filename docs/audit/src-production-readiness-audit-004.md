# Production-Readiness Review of `src/` (apntalk/ami-client)

### 1. Executive Summary (5�10 bullets)
- Verdict: **Not Ready** for 24/7 dialer use at this time due to a P1 invariant violation.
- Production-readiness score: **74% (Not Ready)**.
- Strength: Non-blocking transport design with `stream_select` and partial-write handling in the TCP transport. (`src/Transport/TcpTransport.php:146-238`)
- Strength: Memory bounds enforced across key buffers/queues (write buffer cap, parser buffer cap, event queue capacity, pending-action cap). (`src/Transport/WriteBuffer.php:21-36`, `src/Protocol/Parser.php:23-55`, `src/Core/EventQueue.php:25-53`, `src/Correlation/CorrelationRegistry.php:45-66`)
- Strength: Listener isolation and per-listener exception handling prevents dispatch loops from breaking. (`src/Core/AmiClient.php:522-536`, `src/Cluster/AmiClientManager.php:270-285`)
- Top blocker (P1): Tick loop is **not guaranteed fully non-blocking** when hostname endpoints are allowed; DNS resolution can block inside the reconnect path. (`src/Transport/TcpTransport.php:33-56`, `src/Core/AmiClient.php:418`)
- Other blocker: Async connect fallback can produce a **false-positive �connected� state** when socket extension helpers are unavailable. (`src/Transport/TcpTransport.php:277-283`)
- Invariants: 9/10 pass; **Tick loop fully non-blocking = FAIL (conditional on config)**. All other invariants have evidence in code below.

### 2. Scorecard (0�5 each)
- Architecture/Boundaries: 4
- Non-blocking I/O correctness: 3
- Parser robustness: 4
- Correlation correctness: 4
- Event backpressure: 4
- Reconnect/Health resilience: 4
- Logging/Security: 4
- Failure semantics integrity: 4
- Testability/Extensibility: 3

### 3. Findings (Evidence-Based)

**Severity**: P1 high
**Location**: `src/Transport/TcpTransport.php:33-56`, `src/Core/AmiClient.php:418`
**Why it matters (dialer impact)**: When `enforceIpEndpoints` is disabled, `stream_socket_client()` can perform blocking DNS resolution. Because reconnects are initiated inside `processTick()` (which is called in tight loops), a DNS stall can halt the entire tick loop and starve other servers or timeouts.
**Concrete fix (code-level direction)**: Enforce IP-only endpoints in production (hard fail if hostname is provided), or move DNS resolution out of the tick path (pre-resolve at bootstrap and inject IPs), or adopt a non-blocking resolver and cache to keep `processTick()` free of blocking calls.

**Severity**: P2 medium
**Location**: `src/Transport/TcpTransport.php:277-283`
**Why it matters (dialer impact)**: When the socket extension functions are unavailable, the transport marks the connection as successful without verifying the async connect result. This can yield a false-positive �connected� state, causing actions to be queued to a dead socket and delayed failure feedback.
**Concrete fix (code-level direction)**: Require ext-sockets in production, or add a portable fallback check (e.g., use `stream_get_meta_data()` and a non-blocking write probe with error handling). If no verification is possible, treat the connect as failed and close the socket.

**Severity**: P3 low
**Location**: `src/Core/Logger.php:55-76`, `src/Core/SecretRedactor.php:22-94`
**Why it matters (dialer impact)**: Redaction is key-based only; if secrets are embedded in non-sensitive keys or free-form strings, they can be logged. This is a confidentiality risk under verbose logging.
**Concrete fix (code-level direction)**: Add optional value-based regex redaction (configurable) and ensure action payloads are passed through this redactor before logging structured context.

### 4. Production Readiness Checklist
- [ ] Must-fix blockers (P0/P1)
- [ ] Enforce truly non-blocking reconnects (no DNS or blocking I/O in `processTick`). (`src/Transport/TcpTransport.php:33-56`, `src/Core/AmiClient.php:418`)
- [ ] Recommended improvements (P2/P3)
- [ ] Verify async connect success when socket helpers are unavailable. (`src/Transport/TcpTransport.php:277-283`)
- [ ] Strengthen secret redaction to cover value-based patterns. (`src/Core/SecretRedactor.php:22-94`)

### 5. Suggested Next Steps
1. Enforce IP-only endpoints in production configuration or add async DNS resolution outside the tick loop. (`src/Transport/TcpTransport.php:33-56`)
2. Add a robust async-connect verification fallback for environments without `socket_*` helpers. (`src/Transport/TcpTransport.php:277-283`)
3. Add value-based redaction patterns (configurable) and apply before logging any payloads. (`src/Core/SecretRedactor.php:22-94`)
4. Add tests: non-blocking reconnect path with hostname disabled, connect failure handling without ext-sockets, and value-based redaction coverage.
5. Add tests: parser buffer cap overflow triggers desync handling and forced close. (`src/Protocol/Parser.php:23-63`, `src/Core/AmiClient.php:350-359`)

**Missing tests to add immediately**
- Reconnect path with hostname resolution disabled/enabled, verifying tick loop remains non-blocking. (`src/Transport/TcpTransport.php:33-56`, `src/Core/AmiClient.php:418`)
- Async connect fallback behavior without `socket_import_stream`/`socket_get_option` available. (`src/Transport/TcpTransport.php:277-283`)
- Secret redaction on value-based matches in nested context arrays. (`src/Core/SecretRedactor.php:65-94`)

**Invariants currently violated**
- Tick loop fully non-blocking (DNS resolution possible when hostname endpoints are allowed). (`src/Transport/TcpTransport.php:33-56`, `src/Core/AmiClient.php:418`)

## Invariant Compliance (Explicit)
- Core remains framework-agnostic (no `Illuminate\*` in Core): PASS. (`src/Laravel/Ami.php:8`, `src/Laravel/AmiClientServiceProvider.php:13`)
- Tick loop fully non-blocking (no hidden blocking I/O, DNS, sleep, file I/O): **FAIL** (conditional on `enforceIpEndpoints`). (`src/Transport/TcpTransport.php:33-56`, `src/Core/AmiClient.php:418`)
- No synthetic success when responses are missing: PASS (non-event-only strategies fail with `MissingResponseException`). (`src/Correlation/CorrelationRegistry.php:142-147`)
- Multi-server fairness: no node starvation under capped per-tick budgets: PASS (connect attempt budget + cursor rotation). (`src/Cluster/AmiClientManager.php:202-216`)
- No cross-node ActionID contamination: PASS (ActionIDs are namespaced by `serverKey` and per-client registry). (`src/Correlation/ActionIdGenerator.php:41-41`, `src/Correlation/CorrelationRegistry.php:63-70`)
- Memory bounded in all buffers/queues with enforced caps: PASS. (`src/Transport/WriteBuffer.php:21-36`, `src/Core/EventQueue.php:25-53`, `src/Protocol/Parser.php:23-55`, `src/Correlation/CorrelationRegistry.php:45-66`)
- Pending actions deterministically failed on disconnect/timeout: PASS. (`src/Core/AmiClient.php:383-385`, `src/Correlation/CorrelationRegistry.php:160-177`)
- Listener exceptions cannot break dispatch loops: PASS. (`src/Core/AmiClient.php:522-536`, `src/Cluster/AmiClientManager.php:270-285`)
- Reconnect storms cannot monopolize tick time: PASS (per-tick connect budget). (`src/Health/ConnectionManager.php:299-348`, `src/Cluster/AmiClientManager.php:202-216`)
- Queue drops are observable (logged or counted): PASS (metrics increment and log on drop). (`src/Core/EventQueue.php:48-53`, `src/Core/AmiClient.php:706-716`)
