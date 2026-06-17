# Blockers

## Proof-review blocking defects
**None.** No exact bounded defect was found in the trusted endpoint resolver implementation. No source change
was required or made.

## Non-blocking observations (recorded for maintainers, not defects)
1. **Literal-IP short-circuit asymmetry** — `AmiClientManager::resolveHost()` accepts a literal-IP endpoint
   before consulting the trusted resolver, so a literal IP is not confined to the policy CIDR. This preserves
   pre-existing literal-IP-accepted behavior (not a regression / not an SSRF weakening, since hostnames are
   the untrusted input). The standalone `DefaultTrustedEndpointResolver` *does* validate literal IPs. If a
   consumer wants literal IPs also CIDR-confined via the manager, that would be an additive opt-in. Enhancement.
2. **IPv6 happy-path at manager layer** — the default DNS resolver is IPv4 (`gethostbynamel`); IPv6 is covered
   in the matcher/classifier and supported when a callable supplies AAAA results, but there is no manager-level
   IPv6 acceptance test. Enhancement.
3. **`gethostbynamel` reverse/ordering** — multi-IP `DeterministicAllValid` picks the lowest binary address;
   deterministic and documented. No issue; noted for awareness.

## Pre-existing issues (inherited; NOT caused by this task, NOT blocking)
- 6 full-suite failures from missing untracked parser fixtures + absent README artifact files
  (`full-suite-failure-classification.md`).
- `composer check:core-boundaries` non-zero solely from `ListenCommand.php` usleep
  (`core-boundary-check-review.md`).
- `ConfigLoaderHostnameResolverTest` depends on `DnsTestHook` defined in `NonBlockingConnectTest.php`
  (errors in isolation, green in full suite) — pre-existing test-ordering coupling.

## Gating decisions for the operator/maintainer (not defects)
- Version choice for the eventual tag (minor `v1.1.0` recommended vs `v1.0.2` patch).
- Whether to address the pre-existing fixture/README/boundary issues separately.
- Whether to keep/relocate the pre-existing `.gitignore` `CLAUDE.md` change.
