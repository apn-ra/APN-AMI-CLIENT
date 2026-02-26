# Source Production Readiness Audit 007

## 1. Executive Summary
- **Production readiness verdict:** **72% (Not Ready)**.
- **Classification:** **Not Ready** (<75%).
- **Production readiness state (per AGENTS.md):** **Not Ready**.
- **Top strength #1:** Strong layering and adapter isolation; Laravel dependencies are confined to `src/Laravel/*` (for example [src/Laravel/AmiClientServiceProvider.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Laravel/AmiClientServiceProvider.php:13)), while core/runtime code stays framework-agnostic.
- **Top strength #2:** Correlation and timeout lifecycle are deterministic with bounded pending registry and disconnect failure semantics ([src/Correlation/CorrelationRegistry.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Correlation/CorrelationRegistry.php:45), :63, :163, :181).
- **Top strength #3:** Parser and queues are explicitly bounded (parser cap/frame max, event queue cap, write buffer cap) ([src/Protocol/Parser.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Protocol/Parser.php:25), :29-46; [src/Core/EventQueue.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Core/EventQueue.php:24); [src/Transport/WriteBuffer.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Transport/WriteBuffer.php:21)).
- **Top blocker #1 (P0):** Transport close path preserves unsent bytes, allowing stale actions to leak into future sessions after reconnect.
- **Top blocker #2 (P1):** Tick-path logging is synchronous stdout I/O (`echo`), which can block the event loop under log backpressure.
- **Top blocker #3 (P1):** Event drop logging is per-drop, causing log storms that can dominate tick time under burst conditions.
- **Top blocker #4 (P1):** Laravel `ami:listen` loop is a busy-spin (`while (true)` + `poll*()` with no sleep/block).
- **Top blocker #5 (P2):** Non-blocking mode is not enforced at API boundary; `tickAll($timeoutMs)` and transport tick can be used in blocking mode.

### Hard Invariants Compliance
- Core remains framework-agnostic (no `Illuminate\*` in Core): **PASS**.
- Tick loop is fully non-blocking (no hidden blocking I/O, DNS, sleep, file I/O): **FAIL** (sync stdout logging in tick-path).
- No synthetic success when responses are missing (correlation correctness): **PASS** (guarded by event-only strategy marker).
- Multi-server fairness under capped per-tick budgets: **PASS** (rotating reconnect cursor + global cap).
- No cross-node ActionID contamination: **PASS** (per-node generators and registries).
- Memory is bounded in all buffers/queues with enforced caps: **PASS**.
- Pending actions deterministically failed on disconnect/timeout: **PASS**.
- Listener exceptions cannot break dispatch loops: **PASS**.
- Reconnect storms cannot monopolize tick time: **PASS** (global/per-node connect-attempt caps).
- Queue drops are observable (logged or counted): **PASS**.

## 2. Scorecard (0-5)
- Architecture/Boundaries: **4.5/5**
- Non-blocking I/O correctness: **2.5/5**
- Parser robustness: **4.5/5**
- Correlation correctness: **4.0/5**
- Event backpressure: **3.5/5**
- Reconnect/Health resilience: **4.0/5**
- Logging/Security: **3.0/5**
- Failure semantics integrity: **3.0/5**
- Testability/Extensibility: **4.0/5**

## 3. Findings (Evidence-Based)

### Finding 1
- **Severity:** **P0 blocker**
- **Location:** [src/Transport/TcpTransport.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Transport/TcpTransport.php:129), :140-142, :331-333; [src/Core/AmiClient.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Core/AmiClient.php:797), :805
- **Why it matters (dialer impact):** `close()` drops the socket but does **not** clear `WriteBuffer`. After reconnect, `handleWriteReady()` flushes pending bytes from the prior session. This can replay stale AMI actions after correlation state was already failed/cleaned, causing untracked side effects on PBX and false operational behavior.
- **Concrete fix (code-level direction):** On connection-loss close paths, clear outbound buffer (or maintain per-session epoch and discard pre-epoch bytes). The safe baseline is to call `writeBuffer->clear()` in `close()` when closure is non-graceful, and ensure graceful shutdown path explicitly controls whether unsent bytes may persist.

### Finding 2
- **Severity:** **P1 high**
- **Location:** [src/Core/Logger.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Core/Logger.php:79), :106
- **Why it matters (dialer impact):** Logging uses synchronous `echo` from within tick-path execution contexts. If stdout/stderr consumers are slow or blocked, event loop progress can stall, violating non-blocking expectations for 24/7 dialer workloads.
- **Concrete fix (code-level direction):** Replace direct `echo` with non-blocking logger transport (buffered async sink, UDP/syslog, or batched writer with bounded queue and drop metrics). Keep PSR-3 semantics but decouple I/O from tick thread.

### Finding 3
- **Severity:** **P1 high**
- **Location:** [src/Core/AmiClient.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Core/AmiClient.php:710), :717; [src/Core/EventQueue.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Core/EventQueue.php:48), :53
- **Why it matters (dialer impact):** Every dropped event emits a warning log. Under flood/backpressure this can create massive log amplification and materially steal CPU/time from protocol and correlation processing.
- **Concrete fix (code-level direction):** Add throttled/coalesced drop telemetry (for example, log once per interval with `dropped_delta` + queue depth, while retaining precise counters in metrics).

### Finding 4
- **Severity:** **P1 high**
- **Location:** [src/Laravel/Commands/ListenCommand.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Laravel/Commands/ListenCommand.php:46), :57
- **Why it matters (dialer impact):** The worker loop is a tight busy-spin with no sleep/blocking wait (`pollAll()` / `poll()` repeatedly). This can pin CPU at 100%, increase noise, and reduce host stability in multi-tenant dialer nodes.
- **Concrete fix (code-level direction):** Use bounded blocking wait per iteration (`tickAll(10-50)`), or sleep/yield when no work was processed, with configurable loop cadence.

### Finding 5
- **Severity:** **P2 medium**
- **Location:** [src/Transport/Reactor.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Transport/Reactor.php:51), :85-90; [src/Transport/TcpTransport.php](/mnt/c/Users/ramjf/PhpstormProjects/APN-AmiClient/src/Transport/TcpTransport.php:200), :213-219
- **Why it matters (dialer impact):** API allows blocking `stream_select` by passing `timeoutMs > 0`. This is explicit, but it weakens the “always non-blocking” invariant and can be misused in production loops.
- **Concrete fix (code-level direction):** Enforce non-blocking in core paths (hard-cap to 0 in production runtime), or split APIs into explicit `pollNonBlocking()` vs `tickBlocking()` to avoid accidental misuse.

## 4. Production Readiness Checklist
- [ ] Must-fix blockers (P0/P1): Clear stale write buffer across disconnect/reconnect boundaries.
- [ ] Must-fix blockers (P0/P1): Remove synchronous stdout logging from tick-path or fully decouple with bounded async sink.
- [ ] Must-fix blockers (P0/P1): Throttle/coalesce event-drop warning logs to prevent log storms.
- [ ] Must-fix blockers (P0/P1): Eliminate busy-spin in Laravel worker loop.
- [ ] Recommended improvements (P2/P3): Enforce non-blocking mode at API boundary (or make blocking mode explicit and isolated).

## 5. Suggested Next Steps
1. Fix transport session-boundary semantics (clear outbound buffer on non-graceful close) and add regression tests for “disconnect with pending writes, reconnect, no stale replay”.
2. Introduce bounded async logging sink and make logger backpressure observable (`ami_log_dropped_total`, queue depth).
3. Add drop-log rate limiter in `AmiClient` (interval-based summary with `dropped_delta`).
4. Change Laravel listen loop to bounded blocking tick or controlled sleep/yield; expose loop interval option.
5. Guard core non-blocking invariant by clamping timeout to 0 in production loop path.
6. Add invariant tests for hard requirements:
   - disconnect must purge unsent write bytes
   - no blocking logger side effects under sink backpressure
   - event-drop storms do not exceed logging rate budget
7. Add multi-node fairness stress test with many disconnected nodes and capped connect budget to verify cursor fairness over long runs.
8. Add cross-session contamination test asserting no old ActionIDs/actions are emitted after reconnect.
9. Re-run `vendor/bin/phpunit` in an environment that allows local ephemeral socket binds; current run in this environment failed transport/integration tests due `stream_socket_server()` binding issues.

### What currently prevents 100%
- Session-boundary correctness bug in outbound write handling (P0).
- Hidden blocking/log amplification risks in runtime loop (P1).
- Busy-spin adapter loop and non-blocking invariant not strictly enforced at API boundary (P1/P2).
