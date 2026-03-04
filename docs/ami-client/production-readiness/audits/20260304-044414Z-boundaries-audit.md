# Architecture and Boundary Audit

- Scope: `src/`
- Timestamp (UTC): 2026-03-04T04:44:14Z

## Findings Summary
- Core/Laravel import boundary in code: PASS.
- Layer separation (Transport/Protocol/Correlation/Cluster): PASS.
- Public API leakage: no blocker found.
- Packaging boundary (framework-agnostic install surface): GAP (P1).

## Evidence

### 1) No Laravel symbols in Core code
- Search `Illuminate\\|env\(|config\(|collect\(` in `src/` only returned files under `src/Laravel`:
  - `src/Laravel/AmiClientServiceProvider.php:13`
  - `src/Laravel/Commands/ListenCommand.php:10`
  - `src/Laravel/Ami.php:8`
  - `src/Laravel/Events/AmiEventReceived.php:8`

### 2) Transport does not generate ActionID or manage correlation
- Transport concerns are socket lifecycle and byte I/O:
  - `src/Transport/TcpTransport.php:17-20`, `src/Transport/TcpTransport.php:73-121`, `src/Transport/TcpTransport.php:296-339`, `src/Transport/TcpTransport.php:345-368`
- ActionID generation is in correlation layer:
  - `src/Correlation/ActionIdGenerator.php:36-62`
- Action registration and completion lives in registry:
  - `src/Correlation/CorrelationRegistry.php:67-93`, `src/Correlation/CorrelationRegistry.php:98-130`

### 3) Protocol owns framing/caps/recovery
- Delimiter handling (`\r\n\r\n` + `\n\n` fallback): `src/Protocol/Parser.php:136-151`
- Frame cap enforcement: `src/Protocol/Parser.php:162-164`
- Buffer cap enforcement: `src/Protocol/Parser.php:81-93`
- Desync recovery path: `src/Protocol/Parser.php:250-263`

### 4) Cluster owns fairness and multi-server isolation
- Round-robin cursor + cluster connect budget:
  - `src/Cluster/AmiClientManager.php:247-263`
- Cluster health includes connect attempt budget telemetry:
  - `src/Cluster/AmiClientManager.php:414-418`

### 5) Packaging-level boundary gap
- Root package requires Laravel components unconditionally:
  - `composer.json:14-15`
- This weakens "framework-agnostic core install" because non-Laravel consumers still pull Laravel deps.

## Verdict
- Code-level architecture boundaries are mostly enforced.
- Production boundary score is reduced by packaging dependency coupling (P1).
