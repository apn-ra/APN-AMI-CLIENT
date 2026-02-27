# Non-Blocking Runtime Contract (NBRC) — apntalk/ami-client

## 0) Purpose

This contract defines **what “non-blocking” means** for `apntalk/ami-client` in **24/7 dialer workloads** with **multi-server multiplexing**. It exists to prevent regressions like:

- busy-spin / hot-loop in `ami:listen`
- hidden blocking DNS / connect / close in tick paths
- misleading `timeoutMs` semantics
- starvation due to unfair multiplexing
- runtime drift between API/docs/implementation

This contract is binding for:
- `AmiClientManager::tickAll()`
- `AmiClient::tick()`
- Transport implementations (`TcpTransport`, etc.)
- Laravel worker (`ami:listen`)

---

## 1) Terms & Definitions

### 1.1 Tick
A single call to `tick()` / `tickAll()` that performs bounded work:
- `stream_select` (or equivalent readiness check)
- read bytes (budgeted)
- parse frames (budgeted)
- flush write buffers (non-blocking)
- sweep timeouts (bounded)
- dispatch events (budgeted)
- advance health state machine (bounded)

### 1.2 Production Runtime Path
Any code path executed under:
- Profile A (Pure PHP Worker)
- Profile B (`ami:listen`)
- Profile C (Embedded tick mode)

### 1.3 Blocking
A call is “blocking” if it can wait on external resources **without a strict small upper bound** controlled by the runtime cadence policy.

Examples:
- DNS resolution (blocking libc resolver)
- synchronous stdout logging that can stall
- `sleep()` / `usleep()` inside core tick logic
- connect/close that waits for completion
- select waits longer than allowed by cadence policy

---

## 2) Global Non-Blocking Invariants (Hard MUSTs)

### NB-01: No Blocking Calls in Core Tick Path
**MUST NOT** perform any of the following inside any production tick path:

- DNS resolution (e.g., `gethostbyname`, implicit hostname resolution in connect)
- `sleep()`, `usleep()` (in core)
- blocking connect without async mode
- blocking close/shutdown waits
- direct stdout writes (`echo`, `print`, `fwrite(STDOUT, ...)`) from tick path
- unbounded loops without a readiness wait / yield strategy

**Scope:** Core (`src/Core`, `src/Cluster`, `src/Transport`, `src/Health`, `src/Correlation`, `src/Protocol`)

**Allowed:** bounded `stream_select` wait according to **Section 4 (Cadence Contract)**.

---

### NB-02: Multiplexing Must Use a Single Selector per tickAll()
When multiple servers are active, `AmiClientManager::tickAll()` **MUST**:
- gather streams for all eligible servers
- perform **exactly one** `stream_select()` call (or selector) per tickAll invocation
- then dispatch readiness to each per-server transport

**MUST NOT:** call `stream_select()` per server in tickAll.

---

### NB-03: Tick Work Must be Budgeted (Fairness & Bounded CPU)
For each server, per tick:
- reads, parses, event dispatch, and write flushing **MUST** be bounded by `ClientOptions` budgets:
  - `max_bytes_read_per_tick`
  - `max_frames_per_tick`
  - `max_events_per_tick`
- connect attempts per tickAll bounded by:
  - `max_connect_attempts_per_tick`

**MUST:** ensure a “noisy” server cannot starve others.

---

### NB-04: Session Boundary Safety
On any non-graceful disconnect, the transport **MUST** ensure:
- **no stale outbound bytes** from a prior session can be emitted after reconnect
- write buffer must be **cleared** or **epoch-invalidated** before any reconnect flush

---

## 3) DNS & Endpoint Policy Contract

### NB-10: Default is IP-only in production
If `enforceIpEndpoints = true` (default), then:
- Transport **MUST** reject non-IP endpoints deterministically at construction or open-time with `InvalidConfigurationException`.

### NB-11: Hostname Mode Requires Explicit Resolver Injection
If `enforceIpEndpoints = false`, then:
- hostnames are allowed **ONLY IF** a resolver is provided via config bootstrap
- **MUST NOT** resolve hostnames inside tick / reconnect / transport open path using blocking DNS.

`ConfigLoader::load()` **MUST** support injecting:
- `HostnameResolverInterface` (or equivalent)
- and/or pre-resolved IP endpoints

**If hostname mode enabled but resolver missing:** fail fast with `InvalidConfigurationException` at load time.

---

## 4) Cadence Contract (Worker Loop Must Not Hot-Spin)

This is the “loop killer”.

### NB-20: Worker Cadence Strategy is Mandatory
The worker layer (`ami:listen`) **MUST** use a cadence strategy that ensures:

- No hot-spin under idle conditions
- CPU utilization remains bounded when:
  - no sockets are readable/writable
  - no reconnects are due
  - queues are empty

### NB-21: One of Two Allowed Cadence Modes
Only these two modes are allowed:

#### Mode A — Bounded Wait (Preferred)
- `tickAll($timeoutMs)` performs a selector wait of **up to** `$timeoutMs`.
- Worker loop calls `tickAll($timeoutMs)` with a default of **10–50ms**.

#### Mode B — Non-blocking tick + deterministic yield (Fallback)
- `tickAll(0)` is non-blocking
- Worker loop performs a bounded `usleep(idleSleepUs)` **ONLY in worker layer**, never core
- default `idleSleepUs` is **1,000–10,000µs** (1–10ms)
- yield occurs **only when idle is detected** (no progress)

### NB-22: Idle Detection Must be Explicit
The core tick result MUST expose whether progress occurred:
- bytes read > 0 OR
- bytes written > 0 OR
- frames parsed > 0 OR
- events dispatched > 0 OR
- state transitions occurred OR
- reconnect attempt was performed

Worker uses this to decide whether to yield.

**MUST NOT:** yield blindly (that hurts throughput under load).

---

## 5) `timeoutMs` API Contract (No more misleading semantics)

This must be consistent across:
- interface signatures
- manager implementation
- transport selector call
- docs
- tests

### NB-30: Choose One of Two Contracts (and enforce everywhere)

#### Contract 1 — Honored Timeout (Recommended)
- `tick(timeoutMs)` and `tickAll(timeoutMs)` **honor** the caller timeout
- selector waits up to timeout
- timeout is bounded by safe maximum (e.g., `0..250ms`)
- values outside range: clamp OR reject (pick one, below)

#### Contract 2 — Explicitly Non-blocking Only (Strict)
- `tick(timeoutMs)` must reject any `timeoutMs > 0` with `InvalidArgumentException`
- the API must be updated to avoid implying waiting behavior
- worker cadence must use Mode B yielding

**You MUST pick exactly one contract.**  
**MUST NOT:** accept timeout but clamp to 0 silently.

### NB-31: Validation Rules (if Contract 1)
If honoring timeouts:
- valid range: `0..max_tick_timeout_ms` (default `50ms`, max `250ms`)
- negative: reject
- too large: clamp or reject (choose and document)

### NB-32: “Non-blocking core” still holds
Even under Contract 1, tick is still “non-blocking” in the sense that:
- it may wait only on socket readiness, **never DNS**
- it must always be bounded by timeout
- it must not block on logging or shutdown

---

## 6) Connect/Close Semantics Contract

### NB-40: Async Connect Only Inside Tick Paths
Inside tick-driven connect/reconnect:
- **MUST** use `STREAM_CLIENT_ASYNC_CONNECT`
- **MUST** verify connect completion before transitioning to CONNECTED:
  - via ext-sockets (`socket_get_option(SO_ERROR)`), OR
  - portable fallback: meta-data + non-blocking write probe with safe error detection
- if verification cannot be performed: treat connect as failed and close

### NB-41: Connect Timeout is Wall-Clock, Non-Blocking
`connectTimeoutMs` is maximum duration a node can remain in CONNECTING state.
Expiration:
- transitions to RECONNECT scheduling
- MUST NOT block a tick

### NB-42: Close Must Not Block
Transport `close()` must be immediate and non-blocking.
Graceful logoff:
- performed by enqueueing `Logoff` and relying on normal tick flush
- bounded by a deadline
- if deadline hit: force close

---

## 7) Logging Contract (Non-blocking by design)

### NB-50: No synchronous stdout in tick path
Tick-path logging must use PSR-3 logger only.
If a logger sink is bounded and drops logs:
- MUST increment counters
- MUST emit **throttled structured warning** about drops

### NB-51: Logger must never throw
Logger serialization failures must be contained:
- wrap logger writes in try/catch
- fallback to minimal safe message
- never break loop

---

## 8) Required Runtime Observability (So we can enforce NBRC)

These MUST be emitted (metrics and/or structured logs):

- `tick_duration_ms`
- `tick_progress` (bool)
- `tick_bytes_read`, `tick_bytes_written`
- `tick_frames_parsed`, `tick_events_dispatched`
- `idle_yield_count` (worker layer)
- `selector_wait_ms` (actual waited)
- `connect_attempt_count`, `connect_fail_count`
- `read_timeout_count`
- `log_drop_count`
- `event_drop_count`

---

## 9) Acceptance Tests (Hard Gates)

These tests are mandatory. Merge is blocked if they fail.

### NB-T01: Idle Loop CPU/Cadence Test (Integration)
- run `ami:listen` loop (or a worker harness) with no sockets ready
- for 2–5 seconds
- assert:
  - progress=false for most ticks
  - idle_yield_count > 0 (if Mode B) OR selector_wait_ms > 0 (if Mode A)
  - CPU hot-spin prevented (proxy signal: tick iterations per second is bounded)

**Example threshold:** idle ticks/sec must be < 500 (tune per platform)

### NB-T02: Timeout Contract End-to-End
- call `tickAll(timeoutMs)` with a non-zero value (if Contract 1)
- assert selector actually waits (measurable time delta)
- assert docs + interface + implementation match

If Contract 2:
- assert `timeoutMs > 0` throws deterministically.

### NB-T03: No DNS in Tick Path
- configure hostname endpoint
- assert failure at bootstrap unless resolver provided
- ensure tick does not call blocking DNS

### NB-T04: Async Connect Verification
- simulate connect where handshake fails
- ensure state does not transition to CONNECTED without verification success

### NB-T05: Session Boundary Write Purge
- enqueue bytes, force non-graceful close, reconnect
- assert no stale bytes are flushed post-reconnect

---

## 10) Codex “Strict Follow” Implementation Rules

When implementing changes under this contract, Codex MUST:

1. **Pick the timeout contract** (NB-30 Contract 1 or 2) and enforce everywhere.
2. Implement cadence policy in worker loop per NB-20..NB-22.
3. Ensure tick returns a structured “progress summary” needed for idle detection.
4. Add the acceptance tests NB-T01..NB-T05 and make them CI gates.
5. Do not mark tasks closed unless:
   - the test suite covers the root category AND
   - a subsequent audit no longer reports the finding class.

---

## Recommended Decision (Based on your audits)

Given your looping reports (hot-spin + timeout drift), I recommend:

- **Timeout Contract: Contract 1 (Honored Timeout)** with a bounded max (50ms default, 250ms max)
- Worker cadence: **Mode A (Bounded Wait)** via selector wait
- Provide explicit “progress summary” for correctness and observability

That combination kills both:
- hot-spin
- misleading timeoutMs

---

## Codex Prompt You Can Use (Strict Enforcer)

Copy/paste this into Codex as the instruction header:

```text
You are Codex working on apntalk/ami-client.

AUTHORITATIVE SPEC:
- Follow docs/contracts/non-blocking-runtime-contract.md (NBRC) as a hard contract.
- Any change to tick/tickAll/ami:listen/Transport/Health must satisfy NBRC invariants.
- Choose and enforce exactly one timeout contract (NBRC NB-30) across interface/docs/implementation/tests.
- Implement worker cadence (NBRC NB-20..NB-22) so idle loop never hot-spins.
- Add/maintain acceptance tests NB-T01..NB-T05. CI must fail on regressions.

HARD RULES:
- No blocking DNS/connect/close/logging in tick path.
- No silent clamping of timeoutMs to 0 (either honor or reject).
- tick/tickAll must return a progress summary used for idle detection.
- Do not mark tasks complete unless the corresponding NBRC acceptance tests pass AND the latest audit no longer reports that category.
```
