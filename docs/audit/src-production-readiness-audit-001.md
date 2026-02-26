# Production-Readiness Review of `src/` (apn/ami-client)

### 1. Executive Summary (5–10 bullets)
- Verdict: **Nearly Ready** — strong non-blocking I/O, bounded buffers, and multi-node fairness, but a couple of correctness risks need fixes before 24/7 dialer load.
- Strength: Non-blocking transport + reactor-based multiplexing with per-tick read caps and partial write handling. (`src/Transport/TcpTransport.php:46-235`, `src/Transport/Reactor.php:37-95`)
- Strength: Memory is bounded across core buffers/queues (write buffer, event queue, parser buffer). (`src/Transport/WriteBuffer.php:30-75`, `src/Core/EventQueue.php:38-46`, `src/Protocol/Parser.php:19-52`)
- Strength: Connection health management includes heartbeats, backoff with jitter, and circuit breaker state. (`src/Health/ConnectionManager.php:35-356`)
- Blocker (P1): Hard 64KB frame limit can drop valid AMI responses/events and force disconnects under large payloads. (`src/Protocol/Parser.php:22-112`)
- Blocker (P1): Correlation resolves missing responses as synthetic success, which can hide failures or parser drops. (`src/Correlation/CorrelationRegistry.php:148-156`)
- Risk (P2): Logging does not enforce `queue_depth` as a mandatory field; consistency required by prompt isn’t guaranteed. (`src/Core/Logger.php:55-69`)
- Risk (P2): Secret redaction is key-name only and narrow; other sensitive fields could leak if added to log context. (`src/Core/SecretRedactor.php:12-30`)
- Risk (P2): ActionID length is unbounded; long server keys can yield oversized IDs and log noise. (`src/Correlation/ActionIdGenerator.php:16-35`)

### 2. Scorecard (0–5 each)
- Architecture/Boundaries: 4
- Non-blocking I/O correctness: 4
- Parser robustness: 3
- Correlation correctness: 3
- Event backpressure: 4
- Reconnect/Health resilience: 4
- Logging/Security: 3
- Testability/Extensibility: 3

### 3. Findings (Evidence-Based)

- **Severity**: P1 blocker  
  **Location**: `src/Protocol/Parser.php:22-112`  
  **Why it matters**: A hard 64KB frame cap means any legitimate large AMI response (e.g., `Follows` outputs, big `QueueStatus` dumps) triggers `ProtocolException`, closes the connection, and stalls dialer operations.  
  **Concrete fix**: Make `MAX_FRAME_SIZE` configurable via constructor and surface it in `ClientOptions`. Set a safe default (e.g., 1–4MB) and align it with `FollowsResponseStrategy` limits; include per-action overrides where possible.

- **Severity**: P1 blocker  
  **Location**: `src/Correlation/CorrelationRegistry.php:148-156`  
  **Why it matters**: Completing a pending action with a **synthetic success** when the response is missing can hide parser drops or server errors. Dialer logic may proceed on false positives (e.g., treating failed actions as success).  
  **Concrete fix**: If no response is present when completion is signaled, reject with a typed exception (e.g., `ProtocolException` or `ConnectionLostException`) or keep the action pending until timeout; only construct a synthetic response for explicitly event-only strategies.

- **Severity**: P2 medium  
  **Location**: `src/Core/Logger.php:55-69`  
  **Why it matters**: The prompt requires mandatory log context fields `server_key`, `action_id`, and `queue_depth`. The logger enforces `server_key` and `action_id` only, so `queue_depth` is frequently missing and observability dashboards lose a critical signal.  
  **Concrete fix**: Add `queue_depth` normalization in the logger (default to `null`) and/or require `queue_depth` in all log calls that relate to queues; consider a small helper to standardize context payloads.

- **Severity**: P2 medium  
  **Location**: `src/Core/SecretRedactor.php:12-30`  
  **Why it matters**: Redaction only checks three keys (`secret`, `password`, `variable`). Any sensitive values under different keys (e.g., `auth`, `token`, `key`, `username`, `command`) are not redacted if ever logged.  
  **Concrete fix**: Expand the redaction list, add regex-based key matching, and allow injection of a configurable redaction policy (e.g., from `ClientOptions`).

- **Severity**: P2 medium  
  **Location**: `src/Correlation/ActionIdGenerator.php:16-35`  
  **Why it matters**: ActionIDs are unbounded by length. Large server keys or custom instance IDs can yield oversized ActionIDs that bloat logs, headers, and may violate AMI expectations.  
  **Concrete fix**: Enforce a max ActionID length (e.g., 64–128 chars). If exceeded, hash or truncate `server_key`/`instance_id` with a stable suffix to preserve uniqueness.

### 4. Production Readiness Checklist
- [ ] Must-fix blockers (P0/P1)
- [ ] Parser max frame size is configurable and safe for large AMI responses. (`src/Protocol/Parser.php:22-112`)
- [ ] Correlation does not report success when a response is missing. (`src/Correlation/CorrelationRegistry.php:148-156`)
- [ ] Recommended improvements (P2/P3)
- [ ] Enforce `queue_depth` in structured logs. (`src/Core/Logger.php:55-69`)
- [ ] Expand/configure secret redaction policy. (`src/Core/SecretRedactor.php:12-30`)
- [ ] ActionID length is bounded and validated. (`src/Correlation/ActionIdGenerator.php:16-35`)

### 5. Suggested Next Steps
1. Make parser frame limits configurable and align them with `Follows` handling; add tests for oversized frames and large responses.
2. Adjust correlation completion semantics to avoid synthetic success; add tests covering dropped responses and event-only strategies.
3. Standardize logging context (include `queue_depth`) and add a small helper to avoid omissions.
4. Expand secret redaction policy and add tests for redacting login/command payloads.
5. Add ActionID validation/length limits and tests for truncation/hashing.
6. Add targeted load tests for multi-node fairness and reconnect storms under simulated AMI outages.

Missing tests to add immediately:
- Parser: large frame handling, delimiter edge cases, and recovery behavior.
- Correlation: response-missing completion semantics and timeout paths.
- Transport: partial writes and `fwrite` returning 0 on non-blocking sockets.
- Health: circuit breaker transitions and backoff jitter bounds.
