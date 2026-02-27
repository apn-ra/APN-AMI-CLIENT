# Production Readiness Audit — src/ (NBRC)

Date: 2026-02-27
Scope: `src/`, `config/`, `composer.json`, `docs/contracts/non-blocking-runtime-contract.md`

## 1) Executive Summary
Status: **Nearly Ready**

Top blockers:
- **P1:** `Reactor::tick()` can execute more than one `stream_select()` call within a single `tickAll()` invocation when the first selector fails, violating the strict NB-02 “exactly one selector per tickAll” requirement.
- **P1:** Runtime-path logging is not uniformly contained against logger exceptions; several tick-path log writes are not guarded, which leaves loop-break risk if an injected logger throws (NB-51).

NBRC compliance status: **Partially compliant** with remaining high-severity contract drift.

## 2) NBRC Compliance Matrix
| NBRC Clause | Status | Evidence |
|---|---|---|
| NB-01 No blocking calls in core tick path | **Pass** | Async connect and no blocking DNS in tick paths; hostname resolution is pre-resolved/injected only. `src/Transport/TcpTransport.php:64-153`, `src/Transport/TcpTransport.php:76-118` |
| NB-02 Single selector per tickAll | **Fail** | `Reactor::tick()` runs one selector, then on failure `handleStreamSelectFailure()` performs additional per-transport probe selectors in the same tick. `src/Transport/Reactor.php:97-99`, `src/Transport/Reactor.php:135-173` |
| NB-03 Budgeted tick work | **Pass** | Per-tick budgets and connect attempt caps are enforced across client and manager. `src/Core/AmiClient.php:368-383`, `src/Core/AmiClient.php:536-568`, `src/Cluster/AmiClientManager.php:245-278` |
| NB-04 Session boundary safety | **Pass** | Non-graceful close clears write buffer and reconnect path uses `close(false)`. `src/Transport/TcpTransport.php:158-175`, `src/Transport/TcpTransport.php:420-423` |
| NB-10 IP-only default | **Pass** | Hostname endpoints are rejected when IP enforcement is enabled. `src/Transport/TcpTransport.php:127-133` |
| NB-11 Hostname mode requires resolver injection | **Pass** | Hostname mode fails fast without resolver and validates resolver output IP. `src/Transport/TcpTransport.php:135-150` |
| NB-20 Cadence strategy mandatory | **Pass** | Listen worker enforces bounded cadence and idle-aware yield via tick summary. `src/Laravel/Commands/ListenCommand.php:56-77`, `src/Laravel/Commands/ListenCommand.php:149-162` |
| NB-21 Allowed cadence modes | **Pass** | Mode A bounded wait is used (`tickAll($tickTimeoutMs)`), plus deterministic idle yield only on no progress. `src/Laravel/Commands/ListenCommand.php:58-63`, `src/Laravel/Commands/ListenCommand.php:73-76` |
| NB-22 Idle detection explicit | **Pass** | Tick summary exposes progress and worker yields only on `!hasProgress()`. `src/Core/TickSummary.php:18-28`, `src/Laravel/Commands/ListenCommand.php:73-76` |
| NB-30 Timeout contract chosen/enforced | **Pass** | Contract 1 is implemented with validation and bounded clamp-to-max with observability. `src/Cluster/AmiClientManager.php:294-314`, `src/Transport/Reactor.php:239-259`, `src/Transport/TcpTransport.php:425-447` |
| NB-50 No synchronous stdout in tick path | **Pass** | Runtime uses PSR-3 logger abstraction; logger sink is non-blocking and queue-backed. `src/Core/Logger.php:136-201`, `src/Core/Logger.php:246-270` |
| NB-51 Logger must never throw | **Partial** | Logger implementation is defensive, but many tick-path call sites invoke `$this->logger->warning/error(...)` directly without a containment wrapper at call site. Example runtime paths: `src/Cluster/AmiClientManager.php:238-241`, `src/Transport/Reactor.php:221-225`, `src/Core/AmiClient.php:412-422` |

## 3) Timeout Contract Drift Detection
- Timeout drift previously identified is now remediated: manager/reactor/transport normalize using explicit bounded rules and no silent clamp-to-zero.
- Remaining drift risk: none identified for timeout semantics in current scope.

## 4) Cadence / Hot-Spin Detection
- Idle cadence is explicit and deterministic in worker mode: `ListenCommand` checks `TickSummary::hasProgress()` before yielding.
- No hot-spin pattern detected in current worker loop path.

## 5) Detailed Findings (with file + line)

### P1 — NB-02 strict selector-count violation on selector-failure path
- Problem: NB-02 requires exactly one selector call per `tickAll()`. `Reactor::tick()` performs one main selector and may perform additional `stream_select(..., 0, 0)` probes per transport on failure.
- Dialer impact: During selector error conditions, reactor behavior diverges from contract, adds extra selector churn, and complicates deterministic fairness/debugging guarantees.
- Evidence:
  - `src/Transport/Reactor.php:97-99`
  - `src/Transport/Reactor.php:135-173`
- NBRC clauses: NB-02

### P1 — NB-51 containment gap for injected logger failures in runtime paths
- Problem: NB-51 requires logger failures to be contained so loop execution never breaks. Multiple tick-path call sites use logger directly without `try/catch` guard around the logging call.
- Dialer impact: A throwing logger backend/handler can bubble out of runtime paths and destabilize tick processing under fault conditions.
- Evidence:
  - `src/Cluster/AmiClientManager.php:238-241`
  - `src/Transport/Reactor.php:221-225`
  - `src/Core/AmiClient.php:412-422`
- NBRC clauses: NB-51

## 6) Production Readiness Score
**Score: 84 / 100 — Nearly Ready**

Rationale:
- No current P0 hard-invariant violations detected in scope.
- Two P1 contract drifts remain and should be closed before final production-ready classification.

## Required Audit Disclosures
- **NBRC compliance status**: Partially compliant (P1 violations present).
- **Timeout contract drift**: Not detected.
- **Cadence/hot-spin risk**: Low (idle detection present in worker path).
- **Cross-node contamination risk**: Low (per-node registry/queues and reconnect cursor are isolated).
- **Memory bound enforcement**: Enforced for event queue/write buffer/parser/correlation in current runtime paths.
- **Reconnect fairness**: Present (round-robin reconnect cursor with capped attempts).

## Readiness Classification
**Nearly Ready**
