# Architecture & Boundary Audit

- Timestamp (UTC): `2026-03-04T05:32:58Z`
- Prompt phase: `Phase 1 — Architecture & Boundary Audit`

## Result

- **PASS** for framework boundary and layer separation.

## Boundary Checks

1. Forbidden framework patterns in Core (`src/` excluding `src/Laravel/`):
- Search used: `rg -n "Illuminate\\|\benv\(|\bconfig\(|\bcollect\(" src --glob '!src/Laravel/**'`
- Result: no matches.

2. Core/adapter separation evidence:
- Laravel adapter code is isolated under `src/Laravel/*` (example: `src/Laravel/AmiClientServiceProvider.php`).

## Layer Responsibility Evidence

1. Transport remains framing/correlation agnostic:
- `src/Transport/TcpTransport.php:17-20` (framing-agnostic transport)
- `src/Transport/TcpTransport.php:183-192` (send only writes bytes)
- No ActionID generation in transport path.

2. Correlation owns ActionID and pending registry:
- `src/Core/AmiClient.php:232-239` (ActionID generated via correlation manager)
- `src/Correlation/ActionIdGenerator.php:8-11` (server-aware ActionID contract)
- `src/Correlation/CorrelationRegistry.php:67-93` (pending action register)

3. Protocol owns framing and parsing:
- `src/Protocol/Parser.php:136-167` (frame delimiter detection + frame extraction)
- `src/Protocol/Parser.php:162-164` (max frame enforcement)

4. Cluster manager owns multi-server orchestration/fairness sequencing:
- `src/Cluster/AmiClientManager.php:224-302` (`tickAll`, budgeted reconnect scan, cursor rotation)

## Public API Leakage Check

- Public API remains contract-oriented (`AmiClientInterface`, `TransportInterface`, `CompletionStrategyInterface`) and does not expose internal parser/buffer internals except explicit `snapshot()` diagnostics for operations.

## Risk Map (for this audit pass)

- Stall risk: low (non-blocking transport + bounded tick loops)
- Leak risk: low (bounded buffers/queues in core paths)
- Starvation risk: low (per-tick budgets + reconnect cursor rotation)
- Desync risk: low (parser recovery + typed parser/protocol exceptions)
