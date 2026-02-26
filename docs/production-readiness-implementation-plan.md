# APN AMI Client: Production Readiness Implementation Plan

This document outlines the implementation plan to resolve findings from the `src-production-readiness-audit-003.md` audit and achieve 100% production readiness.

## 1. Objectives
- Eliminate all P1 blockers related to blocking I/O in the tick loop.
- Mitigate P2 risks related to health monitoring and silent data loss.
- Ensure 100% compliance with `APN AMI Client: Production Engineering Guidelines`.

## 2. Phase 1: Transport & Non-Blocking Integrity (Blockers P1)

### 2.1 Enforce Non-Blocking DNS
**Issue:** `stream_socket_client` can block during DNS resolution if a hostname is provided, even with `STREAM_CLIENT_ASYNC_CONNECT`.
**Action:**
- Update `Apn\AmiClient\Cluster\ClientOptions` to set `enforceIpEndpoints = true` by default.
- Modify `Apn\AmiClient\Transport\TcpTransport::open()` to strictly validate that `$host` is an IP address when `enforceIpEndpoints` is active (using `filter_var` with `FILTER_VALIDATE_IP`).
- Update `examples/` and `docs/ami-client/usage-guide.md` to reflect that IP addresses are required for production stability.
- **Verification:** Unit test in `TcpTransportTest` verifying that a `ConnectionException` is thrown when a hostname is passed and enforcement is enabled.

### 2.2 Refactor Tick API for Strict Non-Blocking
**Issue:** `AmiClient::tick($timeoutMs)`, `TcpTransport::tick($timeoutMs)`, and `Reactor::tick($timeoutMs)` allow blocking via `stream_select` if `$timeoutMs > 0`.
**Action:**
- Introduce `Apn\AmiClient\Core\AmiClient::poll()` as a strictly non-blocking alternative that calls `tick(0)`.
- Enforce `$timeoutMs = 0` internally for all tick-based operations that must remain non-blocking.
- Update `Apn\AmiClient\Transport\TcpTransport` and `Apn\AmiClient\Transport\Reactor` documentation to state that for production loops, `tick(0)` must be used.
- Add a recommendation in `usage-guide.md` for external reactor ownership when non-zero timeouts are desired.
- **Verification:** Ensure all internal calls within the library use `0` for timeouts where non-blocking behavior is expected.

## 3. Phase 2: Health Monitoring & Resilience (Risks P2)

### 3.1 Deterministic Reconnect on Heartbeat Failure
**Issue:** Heartbeat failures set status to `DISCONNECTED` but do not force-close the underlying transport, leading to potentially stale or "half-open" connections.
**Action:**
- Update `Apn\AmiClient\Health\ConnectionManager::recordHeartbeatFailure()` to signal a required closure.
- In `Apn\AmiClient\Core\AmiClient::processTick()`, detect when heartbeat failure threshold is reached and call `forceClose('Max heartbeat failures')`.
- This ensures that pending actions are failed, the parser is reset, and the transport is cleanly terminated before reconnection starts.
- **Verification:** Integration test simulating unresponsive AMI server (no heartbeat response) and verifying the client closes the socket and enters reconnection backoff.

## 4. Phase 3: Observability & Backpressure (Risks P2)

### 4.1 Observability for Correlation Event Drops
**Issue:** Multi-response actions (e.g., `CoreShowChannels`) silently drop events if they exceed the `maxMessages` safety cap.
**Action:**
- Update `Apn\AmiClient\Correlation\CorrelationRegistry::handleEvent()` to detect when `maxMessages` is reached.
- Emit a `WARNING` log with context: `action_id`, `server_key`, `strategy_class`, and current message count.
- Increment a Prometheus-compatible counter: `ami_correlation_events_dropped_total` (labeled by `server_key`).
- **Verification:** Unit test in `CorrelationRegistryTest` pushing events beyond the strategy limit and asserting log output and metric increment.

## 5. Phase 4: Documentation & Standards Compliance

### 5.1 Documentation Updates
- Update `docs/ami-client/usage-guide.md` to include:
    - Requirement for IP-only endpoints for 24/7 stability.
    - Explanation of the `poll()` vs `tick()` methods.
    - Proper handling of `AmiTimeoutException` when correlation event caps are hit.
- Update `docs/tasks.md` to mark these production-readiness tasks as planned/in-progress.

## 6. Verification and Testing Strategy
1. **Unit Coverage:** Ensure 100% coverage for new logic in `TcpTransport`, `CorrelationRegistry`, and `AmiClient`.
2. **Failure Simulation:** Use `tests/Integration/MockAmiServer` (if available) or raw socket mocks to simulate DNS failures, heartbeat timeouts, and event floods.
3. **Soak Test Verification:** Re-run the 24-hour soak test with the new IP-only and deterministic reconnect logic to confirm stability.
4. **Memory Stability:** Assert zero memory growth after forced heartbeat disconnects.
