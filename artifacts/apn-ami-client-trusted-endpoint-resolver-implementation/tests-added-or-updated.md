# Tests Added / Updated

No existing tests were modified. Three new test files (35 tests, 68 assertions, all green).

## `tests/Unit/Cluster/Endpoint/DefaultTrustedEndpointResolverTest.php`
Offline (injected DNS hook). Covers the required matrix:

| Matrix item | Test |
|---|---|
| 1 Default-strict literal IP accepted | `test_literal_ip_inside_cidr_is_accepted` |
| 2 Untrusted hostname rejected **before DNS** (asserts 0 DNS calls) | `test_untrusted_hostname_rejected_before_dns` |
| 3 Exact alias `apntalk-asterisk-app` → validated IP | `test_exact_alias_resolves_to_validated_ip` |
| 4 Pattern alias `apntalk-asterisk-node-{a,b}-app` accepted | `test_pattern_alias_is_accepted` |
| 5 Resolved IP outside CIDR rejected | `test_resolved_ip_outside_cidr_rejected` |
| 6 Unresolvable alias | `test_unresolved_alias_rejected` |
| 7 Multiple IPs default reject → ambiguous | `test_multiple_ips_rejected_under_default_policy` |
| 7b Multiple IPs mixed validity → fail-closed | `test_multiple_ips_mixed_validity_rejected` |
| 8 Multiple IPs all-valid → deterministic lowest | `test_multiple_ips_all_valid_deterministic_selection` |
| 9 Loopback rejected by default / allowed only when explicit | `test_loopback_rejected_by_default`, `test_loopback_accepted_only_when_explicitly_allowed` |
| 10 Metadata `169.254.169.254` rejected | `test_cloud_metadata_address_rejected` |
| 11 Public IP rejected under local-only CIDR | `test_public_ip_rejected_when_only_local_cidr_allowed` |
| gateway/bridge denial | `test_explicitly_denied_gateway_address_rejected` |
| network/broadcast boundary denial | `test_network_and_broadcast_addresses_rejected` |
| 12 Policy missing trust → ResolverUnavailable | `test_policy_without_allowlist_is_unavailable`, `test_policy_without_cidr_is_unavailable` |
| Exception taxonomy is `InvalidConfigurationException` (BC) | `test_all_endpoint_exceptions_extend_invalid_configuration` |
| Misconfig (pattern/CIDR) rejected | `test_invalid_pattern_is_rejected`, `test_invalid_cidr_is_rejected` |

## `tests/Unit/Cluster/Endpoint/CidrMatcherTest.php`
IPv4/IPv6 membership, family mismatch, network/broadcast computation, invalid CIDR rejection, and
`IpClassifier` categories (incl. metadata detection).

## `tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php`
Manager/ConfigLoader wiring (offline; reads transport `remoteHost` via reflection):

| Matrix item | Test |
|---|---|
| 13 enforce=true + policy → alias accepted; **transport gets literal IP** | `test_enforce_true_with_policy_accepts_trusted_alias` |
| 14 enforce=true + no policy → hostname rejected | `test_enforce_true_without_policy_rejects_hostname` |
| Untrusted host rejected even with policy | `test_untrusted_host_rejected_with_policy_installed` |
| Alias out-of-CIDR rejected at bootstrap | `test_trusted_alias_out_of_cidr_rejected` |
| 15 Resolver returning non-IP contained | `test_resolver_returning_non_ip_is_contained` |
| 16 Result IP flows to TcpTransport (literal, not hostname) | asserted in items 13 / explicit-resolver / literal tests |
| 17 enforce=false + no policy/resolver → rejected (fail-closed) | `test_enforce_false_without_policy_or_resolver_rejected` |
| Declared-but-unusable policy fails closed at load | `test_declared_policy_without_trust_is_unavailable_at_load` |
| Explicit resolver instance honored | `test_explicit_resolver_instance_is_used` |
| Literal IP still accepted by default | `test_literal_ip_endpoint_still_accepted_by_default` |

## Compatibility / governance (matrix 18–21)
- 18 Existing `ConfigLoaderHostnameResolverTest` (legacy callable, enforce=false) still green in the full
  suite (verified alongside `NonBlockingConnectTest` which defines its DNS helper).
- 19 New exceptions are `InvalidConfigurationException` — `test_all_endpoint_exceptions_extend_invalid_configuration`.
- 20 No secret in messages (host/IP/CIDR only); `composer check:core-boundaries` adds no new violations.
- 21 No Docker socket dependency introduced.
