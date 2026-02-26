# APN AMI Client: Production Readiness Implementation Tasks

This document tracks the granular tasks required to achieve 100% production readiness as defined in the `production-readiness-implementation-plan.md`.

## Phase 1: Transport & Non-Blocking Integrity (Blockers P1)

### 2.1 Enforce Non-Blocking DNS
- [ ] Update `Apn\AmiClient\Cluster\ClientOptions` to set `enforceIpEndpoints = true` by default.
- [ ] Modify `Apn\AmiClient\Transport\TcpTransport::open()` to strictly validate that `$host` is an IP address when `enforceIpEndpoints` is active (using `filter_var` with `FILTER_VALIDATE_IP`).
- [ ] Audit all `examples/` and update them to reflect IP address usage or add warnings about hostname blocking.
- [ ] Add a unit test in `tests/Unit/Transport/TcpTransportTest.php` verifying that a `ConnectionException` (or appropriate exception) is thrown when a hostname is passed while `enforceIpEndpoints` is true.

### 2.2 Refactor Tick API for Strict Non-Blocking
- [ ] Add `poll(): void` method to `Apn\AmiClient\Core\AmiClient` that internally calls `tick(0)`.
- [ ] Update `Apn\AmiClient\Transport\TcpTransport::tick()` documentation to explicitly recommend `0` as the timeout for non-blocking production loops.
- [ ] Update `Apn\AmiClient\Transport\Reactor::tick()` documentation to explicitly recommend `0` as the timeout for non-blocking production loops.
- [ ] Audit internal library code to ensure any automatic or background `tick` calls use a `0` timeout.
- [ ] Verify that `AmiClient::tick(0)` behaves identically to the new `poll()` method via unit tests.

## Phase 2: Health Monitoring & Resilience (Risks P2)

### 3.1 Deterministic Reconnect on Heartbeat Failure
- [ ] Enhance `Apn\AmiClient\Health\ConnectionManager` to provide a way to check if a connection must be force-closed due to heartbeat failure (e.g., `shouldForceClose()`).
- [ ] Modify `Apn\AmiClient\Core\AmiClient::processTick()` to check the health status and trigger `forceClose('Max heartbeat failures')` when the threshold is reached.
- [ ] Ensure `forceClose` correctly purges the correlation registry and resets the protocol parser.
- [ ] Create an integration test in `tests/Integration/HeartbeatResilienceTest.php` that simulates an unresponsive server and asserts that the client closes the connection and initiates backoff.

## Phase 3: Observability & Backpressure (Risks P2)

### 4.1 Observability for Correlation Event Drops
- [ ] Modify `Apn\AmiClient\Correlation\CorrelationRegistry::handleEvent()` to check against `CompletionStrategy::getMaxMessages()`.
- [ ] Add `WARNING` level logging in `CorrelationRegistry` when an event is dropped due to the safety cap, including `action_id` and `server_key`.
- [ ] Implement (or ensure hook exists for) a counter metric `ami_correlation_events_dropped_total` with `server_key` label.
- [ ] Add a unit test in `tests/Unit/Correlation/CorrelationRegistryTest.php` that triggers the message cap and verifies the warning log and metric increment.

## Phase 4: Documentation & Standards Compliance

### 5.1 Documentation Updates
- [ ] Update `docs/ami-client/usage-guide.md` to:
    - [ ] Document the `enforceIpEndpoints` option and its importance for non-blocking I/O.
    - [ ] Add a section explaining `poll()` vs `tick($timeout)`.
    - [ ] Provide a code example for handling `AmiTimeoutException` when correlation limits are reached.
- [ ] Update `docs/tasks.md` to synchronize with this checklist and mark items as "In Progress" or "Completed".

## Phase 5: Verification and Final Tests

### 6.1 Final Validation
- [ ] Achieve 100% test coverage for all modified lines in `TcpTransport`, `CorrelationRegistry`, `ConnectionManager`, and `AmiClient`.
- [ ] Run the 24-hour Soak Test (`tests/Performance/SoakTest.php`) and confirm zero memory growth and no unexpected blocking.
- [ ] Execute the "Reconnect Storm" simulation to verify backoff and jitter under load.
- [ ] Verify that no `Illuminate\*` imports have leaked into the `src/Core`, `src/Transport`, or `src/Correlation` directories.
