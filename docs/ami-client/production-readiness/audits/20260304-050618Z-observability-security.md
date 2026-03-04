# Observability & Security Audit

- Timestamp (UTC): 2026-03-04T05:06:18Z
- Prompt phase: 3

## O1 Logging Stack
- Core logging is PSR-3 (`Psr\Log\LoggerInterface`, `AbstractLogger`).
- Null logger fallback exists in core components:
  - `src/Correlation/CorrelationRegistry.php:58`
  - `src/Health/ConnectionManager.php:53`
  - `src/Transport/TcpTransport.php:56`
- Status: PASS

## O2 Required Context Fields
- Logger enforces presence of `server_key`, `action_id`, `queue_depth` in payload shape:
  - `src/Core/Logger.php:89-100`
- Key runtime logs include queue depth and action_id where applicable:
  - action backpressure logs: `src/Core/AmiClient.php:239-244`, `287-293`
  - drop summaries with queue depth: `src/Core/AmiClient.php:850-856`
- Status: PASS

## O3 Secret Redaction
- Redactor masks sensitive keys and token/password patterns:
  - `src/Core/SecretRedactor.php:24-43`, `83-93`, `120-135`
- Logger applies redaction before write:
  - `src/Core/Logger.php:102-107`
- Parser debug preview redacts secret-like header patterns:
  - `src/Protocol/Parser.php:238-241`
- Status: PASS

## O4 Typed Errors / Actionable Taxonomy
- Explicit exception classes exist for key failure domains (`BackpressureException`, `ActionErrorResponseException`, `AmiTimeoutException`, `ParserDesyncException`, etc.) under `src/Exceptions/`.
- Correlation maps protocol error responses to typed exception:
  - `src/Correlation/CorrelationRegistry.php:114-121`
- Status: PASS

## O5 Listener/Logger Isolation
- Listener exceptions are isolated and logged:
  - `src/Core/AmiClient.php:564-572`, `576-585`
  - `src/Cluster/AmiClientManager.php:349-357`, `363-370`
- Logger failures are intentionally contained:
  - `src/Core/AmiClient.php:973-989`
  - `src/Cluster/AmiClientManager.php` (safeLog catch)
- Status: PASS

## Observability/Security Verdict
- No P0/P1 security defects identified from static audit.
- Operational blocker remains chaos/runtime environment instability, not redaction/logging design.
