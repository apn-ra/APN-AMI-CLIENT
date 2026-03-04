# Chaos Harness Plan

## Scope
Build a deterministic, AMI-like fake server harness for fault injection while reusing existing PHPUnit unit/integration tests as the first evidence layer.

## Detected Test Stack
- Runner: PHPUnit 12 (`phpunit.xml` suites: `unit`, `integration`, `performance`)
- Runtime: PHP 8.4 CLI
- Existing test layout: `tests/Unit`, `tests/Integration`, `tests/Performance`

## Chosen Placement
- Harness code: `tests/Chaos/Harness/`
- Scenario runner entrypoint: `tests/Chaos/run_scenario.php`
- Scenario definitions: `docs/ami-client/chaos/scenarios/*.json`
- Evidence output: `docs/ami-client/chaos/fixtures/` and `docs/ami-client/chaos/metrics/`

## Harness Architecture
- `FakeAmiServer` (non-blocking TCP)
- Accepts multiple client connections
- Reads inbound action frames (best-effort parse)
- Supports timed script steps and action-triggered responses
- Supports:
  - banner emission
  - `\r\n\r\n` and `\n\n` delimiters
  - partial/chunked writes
  - delayed writes with scheduled timestamps
  - interleaved messages via script ordering
  - garbage bytes
  - connection close events

## Risk Map
- Stall risk: mitigated with non-blocking sockets + per-tick stepping in harness
- Leak risk: capped parser/event/write buffers already enforced in core (`Parser`, `EventQueue`, `WriteBuffer`)
- Starvation risk: existing manager fairness and per-tick budgets validated by tests
- Desync risk: parser desync recovery/oversize rejection covered in parser tests

## Runner Flow
1. Load scenario JSON or use smoke default.
2. Start fake server.
3. Connect client socket and emit probe action.
4. Tick harness until terminal condition or timeout.
5. Persist output for fixture evidence.

## Stop Condition Check
Plan complete. Harness folder and runner entrypoint are now defined and implemented.
