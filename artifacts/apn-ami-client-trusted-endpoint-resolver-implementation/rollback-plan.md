# Rollback Plan

Nothing was committed, tagged, or published, so rollback is a clean working-tree
operation. HEAD is unchanged at `d029ab6a8366d511259f586cce9b520bab835961`.

## Full revert (discard the whole implementation)
```bash
cd /home/ra/Documents/apn_projects/APN-AMI-CLIENT

# 1. Restore the 4 tracked files modified by this task to HEAD
git checkout -- src/Exceptions/InvalidConfigurationException.php \
                src/Cluster/AmiClientManager.php \
                src/Cluster/ConfigLoader.php \
                config/ami-client.php

# 2. Remove the new (untracked) source, tests, and artifacts
rm -rf src/Cluster/Endpoint
rm -f  src/Exceptions/UntrustedEndpointHostException.php \
       src/Exceptions/EndpointHostUnresolvableException.php \
       src/Exceptions/ResolvedIpOutsideAllowedCidrException.php \
       src/Exceptions/AmbiguousEndpointResolutionException.php \
       src/Exceptions/ResolverUnavailableException.php
rm -rf tests/Unit/Cluster/Endpoint
rm -f  tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php
rm -rf artifacts/apn-ami-client-trusted-endpoint-resolver-implementation
```
Note: `.gitignore` is intentionally **not** reverted here — its current modification predates this task
(a prior `/init`).

## Partial rollback
- The resolver/policy/exception classes are self-contained. Reverting only `AmiClientManager.php` and
  `ConfigLoader.php` (step 1, those two files) disables the feature while leaving the new classes unused and
  harmless (they are never instantiated unless a policy/resolver is supplied).

## Verify after rollback
```bash
git status --short          # only the pre-existing .gitignore change should remain
vendor/bin/phpunit          # same 6 pre-existing failures, nothing new
```
