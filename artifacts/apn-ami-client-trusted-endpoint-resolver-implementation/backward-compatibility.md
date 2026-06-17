# Backward Compatibility

## Preserved (no behavior change for existing consumers)
- Default `enforce_ip_endpoints=true` and literal-IP-only when **no policy** is installed.
- `TcpTransport` connect-time IP guard unchanged (resolver yields a literal IP).
- Legacy `?callable $hostnameResolver` on `ConfigLoader::load()` / `AmiClientManager::__construct()` kept.
  The enforce=false legacy path is unchanged; `ConfigLoaderHostnameResolverTest` remains green.
- New exceptions extend `InvalidConfigurationException` → existing `catch` blocks still catch them.
- `ClientOptions` and `ServerConfig` shapes unchanged.

## Additive only
- New `Apn\AmiClient\Cluster\Endpoint\*` types and `Apn\AmiClient\Exceptions\*` subclasses.
- New optional trailing parameters on `AmiClientManager::__construct` (`$trustedResolver`) and
  `ConfigLoader::load` (`$trustedResolver`, `$dnsResolver`). All default to `null`.
- New optional `config['trusted_endpoint_policy']` (absent by default).

## One visibility change
- `InvalidConfigurationException` changed `final class` → `class` so trust exceptions can extend it. This is
  not a breaking change (no consumer can have been required to *not* subclass it; behavior/factories
  unchanged). Confirmed no `instanceof`/`final`-dependent assumptions in the repo.

## Semantics change to release-note
`enforce_ip_endpoints=true` gains the ability to admit policy-validated hostnames. For consumers with no
policy this is a no-op. This is a widening under explicit opt-in → BC-safe. Suggested version: **minor**
(`v1.1.0`); `v1.0.2` acceptable if treated as a safe additive patch (maintainer call). **No tag created in
this task.**

## Verification
- Existing tests unaffected by these changes (the only failing tests are pre-existing, data/environment
  related — see `validation-results.md`).
- `composer.json` / `composer.lock` unchanged; PHP `>=8.4` baseline unchanged.
