# PHP Lint Review

`php -l` run on every new and modified PHP file (no file modified during review).

## Files linted
New source (`src/Cluster/Endpoint/`):
- `TrustedEndpointResolverInterface.php`, `DefaultTrustedEndpointResolver.php`, `EndpointResolutionResult.php`,
  `AllowedEndpointHostPolicy.php`, `ReservedRangePolicy.php`, `MultiIpPolicy.php`, `CidrMatcher.php`,
  `IpClassifier.php`

New exceptions (`src/Exceptions/`):
- `UntrustedEndpointHostException.php`, `EndpointHostUnresolvableException.php`,
  `ResolvedIpOutsideAllowedCidrException.php`, `AmbiguousEndpointResolutionException.php`,
  `ResolverUnavailableException.php`

Modified:
- `src/Cluster/AmiClientManager.php`, `src/Cluster/ConfigLoader.php`,
  `src/Exceptions/InvalidConfigurationException.php`, `config/ami-client.php`

New tests:
- `tests/Unit/Cluster/Endpoint/DefaultTrustedEndpointResolverTest.php`,
  `tests/Unit/Cluster/Endpoint/CidrMatcherTest.php`,
  `tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php`

## Result
```
ALL FILES: No syntax errors detected
```
Runtime: PHP 8.4.22 (matches the package `php >=8.4` baseline).

**PHP lint review: PASS.**
