# Backward Compatibility Review

## Q10 — Legacy callable `hostnameResolver` path backward-compatible? **YES.**
- `ConfigLoader::load` and `AmiClientManager::__construct` keep the `?callable $hostnameResolver` parameter
  in its original position; new params are appended after it.
- The enforce=false legacy path (`resolveHostname()` using the callable) is unchanged.
- `tests/Integration/ConfigLoaderHostnameResolverTest` (enforce=false + injected callable) passes when run
  with its helper class available (it shares `DnsTestHook` defined in `NonBlockingConnectTest.php`); verified
  green in the touched-area regression run (53/126) and the full suite.

## Additive-only confirmation
- New types and exceptions are net-new; nothing removed or renamed.
- New constructor/loader parameters are optional with `null` defaults → existing call sites compile and
  behave identically.
- `config['trusted_endpoint_policy']` is absent by default → strict mode unchanged.
- New exceptions extend `InvalidConfigurationException` → existing catch blocks keep working.

## Old call sites preserved
- `ConfigLoader::load($config)` / `($config, $logger)` / `($config, $logger, $callable)` — all still valid.
- `new AmiClientManager($registry, $options, $logger, ...)` — still valid; `$trustedResolver` defaults null.

## Config defaults keep strict mode
- `ClientOptions::enforceIpEndpoints` default `true`.
- `config/ami-client.php` adds only a **commented** example block; no active default changed.

## Regression evidence
- Touched-area suite (`ConfigLoaderTest`, `AmiClientManagerTest`, `ClientOptions*Test`, `TcpTransportTest`,
  `NonBlockingConnectTest`, `ConfigLoaderHostnameResolverTest`): **53 tests / 126 assertions, all pass.**
- No existing test required modification.

**Backward compatibility review: PASS.**
