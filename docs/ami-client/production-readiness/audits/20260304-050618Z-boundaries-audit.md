# Boundaries Audit

- Timestamp (UTC): 2026-03-04T05:06:18Z
- Prompt phase: 1 (architecture and boundary audit)

## Risk Map
- Stall risk: low in architecture boundaries, higher in runtime behavior (`tickAll`, reconnect loops).
- Leak risk: bounded structures present; verify caps remain wired from options.
- Starvation risk: managed by reconnect cursor and per-tick budgets.
- Desync risk: isolated to parser framing/recovery logic.

## Boundary Checks

### B1. Laravel imports in Core
- Search: `rg -n "Illuminate\\|env\(|config\(|collect\(" src`
- Result: only `src/Laravel/*` contains `Illuminate\*`.
- Evidence:
  - `src/Laravel/AmiClientServiceProvider.php:13`
  - `src/Laravel/Commands/ListenCommand.php:10`
  - no hits in `src/Core`, `src/Transport`, `src/Protocol`, `src/Correlation`, `src/Cluster`.
- Status: PASS

### B2. Transport remains I/O-only
- `src/Transport/TcpTransport.php` performs socket open/read/write/flush, non-blocking setup, and buffer handling.
- No ActionID generation or correlation registry access in transport.
- Evidence:
  - non-blocking connect/read/write: `src/Transport/TcpTransport.php:79-87`, `104-107`, `296-339`, `345-368`
  - action correlation remains external; no `Correlation*` use in transport file.
- Status: PASS

### B3. Correlation ownership
- ActionID generation and pending registry are owned by correlation layer and called by client manager wiring.
- Evidence:
  - generator: `src/Correlation/ActionIdGenerator.php:11-64`
  - registry handling: `src/Correlation/CorrelationRegistry.php:67-200`
  - client delegates ActionID creation to correlation manager: `src/Core/AmiClient.php:230-233`
- Status: PASS

### B4. Protocol ownership
- Parser owns framing (`\r\n\r\n`, `\n\n`), caps, and recovery.
- Evidence:
  - delimiter selection: `src/Protocol/Parser.php:134-153`
  - frame size cap: `src/Protocol/Parser.php:163-165`
  - buffer cap + recovery: `src/Protocol/Parser.php:79-104`, `244-263`
- Status: PASS

### B5. Cluster fairness and isolation
- Manager uses reconnect cursor and cluster-level connect-attempt budget.
- Per-node failures are isolated in `try/catch` within tick loop.
- Evidence:
  - connect budget and cursor: `src/Cluster/AmiClientManager.php:246-263`
  - node isolation: `src/Cluster/AmiClientManager.php:272-278`
  - health budget telemetry: `src/Cluster/AmiClientManager.php:414-418`
- Status: PASS

## Boundary Verdict
- No boundary P0 found.
- Residual concern: lifecycle semantics use `READY`/`READY_DEGRADED` rather than explicit `CONNECTED_HEALTHY` naming; functionality present but naming mismatch remains a documentation/API clarity gap.
