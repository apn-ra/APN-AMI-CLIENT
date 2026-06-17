<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster\Endpoint;

use Apn\AmiClient\Cluster\Endpoint\AllowedEndpointHostPolicy;
use Apn\AmiClient\Cluster\Endpoint\DefaultTrustedEndpointResolver;
use Apn\AmiClient\Cluster\Endpoint\MultiIpPolicy;
use Apn\AmiClient\Cluster\Endpoint\ReservedRangePolicy;
use Apn\AmiClient\Exceptions\AmbiguousEndpointResolutionException;
use Apn\AmiClient\Exceptions\EndpointHostUnresolvableException;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Exceptions\ResolvedIpOutsideAllowedCidrException;
use Apn\AmiClient\Exceptions\ResolverUnavailableException;
use Apn\AmiClient\Exceptions\UntrustedEndpointHostException;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the package-owned trusted endpoint resolver (RB-ENFORCE-IP,
 * Authority A). Uses an injected DNS hook — no real DNS, no network, no secrets.
 *
 * Test-only documentation networks are used for CIDRs:
 *   172.30.10.0/24 and 10.250.0.0/16.
 */
final class DefaultTrustedEndpointResolverTest extends TestCase
{
    /** @var array<string, array<int, string>> */
    private array $dnsMap = [];
    private int $dnsCalls = 0;

    protected function setUp(): void
    {
        $this->dnsMap = [
            'apntalk-asterisk-app' => ['172.30.10.20'],
            'apntalk-asterisk-node-a-app' => ['10.250.5.7'],
            'apntalk-asterisk-node-b-app' => ['172.30.10.30'],
        ];
        $this->dnsCalls = 0;
    }

    private function dns(): callable
    {
        return function (string $host): array {
            $this->dnsCalls++;

            return $this->dnsMap[$host] ?? [];
        };
    }

    private function resolver(?AllowedEndpointHostPolicy $policy = null): DefaultTrustedEndpointResolver
    {
        $policy ??= new AllowedEndpointHostPolicy(
            exactAllowlist: ['apntalk-asterisk-app'],
            allowlistPatterns: ['/^apntalk-asterisk-[a-z0-9-]+-app$/'],
            allowedCidrs: ['172.30.10.0/24', '10.250.0.0/16'],
        );

        return new DefaultTrustedEndpointResolver($policy, $this->dns());
    }

    // 1. Literal IP under a policy → accepted (and returned verbatim).
    public function test_literal_ip_inside_cidr_is_accepted(): void
    {
        $result = $this->resolver()->resolve('172.30.10.50');

        $this->assertSame('172.30.10.50', $result->validatedIp);
        $this->assertSame('172.30.10.50', $result->originalHost);
        $this->assertSame(0, $this->dnsCalls, 'Literal IP must not trigger DNS');
    }

    // 2. Untrusted hostname → rejected BEFORE any DNS lookup.
    public function test_untrusted_hostname_rejected_before_dns(): void
    {
        try {
            $this->resolver()->resolve('evil.example.com');
            $this->fail('Expected UntrustedEndpointHostException');
        } catch (UntrustedEndpointHostException $e) {
            $this->assertSame('evil.example.com', $e->getEndpointHost());
            $this->assertSame(0, $this->dnsCalls, 'Untrusted host must never be resolved');
        }
    }

    // 3. Allowlisted exact alias resolving into an allowed CIDR → validated IP.
    public function test_exact_alias_resolves_to_validated_ip(): void
    {
        $result = $this->resolver()->resolve('apntalk-asterisk-app');

        $this->assertSame('172.30.10.20', $result->validatedIp);
        $this->assertSame('apntalk-asterisk-app', $result->originalHost);
    }

    // 4. Allowlisted pattern alias (multi-PBX provider node) → accepted.
    public function test_pattern_alias_is_accepted(): void
    {
        $this->assertSame('10.250.5.7', $this->resolver()->resolve('apntalk-asterisk-node-a-app')->validatedIp);
        $this->assertSame('172.30.10.30', $this->resolver()->resolve('apntalk-asterisk-node-b-app')->validatedIp);
    }

    // 5. Allowlisted alias resolving outside the allowed CIDR → rejected.
    public function test_resolved_ip_outside_cidr_rejected(): void
    {
        $this->dnsMap['apntalk-asterisk-node-a-app'] = ['192.168.99.5'];

        $this->expectException(ResolvedIpOutsideAllowedCidrException::class);
        $this->resolver()->resolve('apntalk-asterisk-node-a-app');
    }

    // 6. Unresolvable allowlisted alias → unresolvable exception.
    public function test_unresolved_alias_rejected(): void
    {
        $this->dnsMap['apntalk-asterisk-node-a-app'] = [];

        $this->expectException(EndpointHostUnresolvableException::class);
        $this->resolver()->resolve('apntalk-asterisk-node-a-app');
    }

    // 7. Multiple IPs under default reject policy → ambiguous, fail-closed.
    public function test_multiple_ips_rejected_under_default_policy(): void
    {
        $this->dnsMap['apntalk-asterisk-node-a-app'] = ['172.30.10.20', '172.30.10.21'];

        $this->expectException(AmbiguousEndpointResolutionException::class);
        $this->resolver()->resolve('apntalk-asterisk-node-a-app');
    }

    // 8. Multiple IPs, all valid, deterministic policy → lowest deterministic pick.
    public function test_multiple_ips_all_valid_deterministic_selection(): void
    {
        $policy = new AllowedEndpointHostPolicy(
            exactAllowlist: ['apntalk-asterisk-app'],
            allowedCidrs: ['172.30.10.0/24'],
            multiIp: MultiIpPolicy::DeterministicAllValid,
        );
        $this->dnsMap['apntalk-asterisk-app'] = ['172.30.10.40', '172.30.10.22', '172.30.10.31'];

        $result = (new DefaultTrustedEndpointResolver($policy, $this->dns()))->resolve('apntalk-asterisk-app');

        $this->assertSame('172.30.10.22', $result->validatedIp, 'Lowest address selected deterministically');
    }

    // 7b. Multiple IPs, one out-of-CIDR, deterministic policy → fail-closed.
    public function test_multiple_ips_mixed_validity_rejected(): void
    {
        $policy = new AllowedEndpointHostPolicy(
            exactAllowlist: ['apntalk-asterisk-app'],
            allowedCidrs: ['172.30.10.0/24'],
            multiIp: MultiIpPolicy::DeterministicAllValid,
        );
        $this->dnsMap['apntalk-asterisk-app'] = ['172.30.10.40', '8.8.8.8'];

        $this->expectException(AmbiguousEndpointResolutionException::class);
        (new DefaultTrustedEndpointResolver($policy, $this->dns()))->resolve('apntalk-asterisk-app');
    }

    // 9. localhost/loopback rejected unless explicitly allowed.
    public function test_loopback_rejected_by_default(): void
    {
        $policy = new AllowedEndpointHostPolicy(
            exactAllowlist: ['localhost'],
            allowedCidrs: ['127.0.0.0/8'],
        );
        $this->dnsMap['localhost'] = ['127.0.0.1'];

        $this->expectException(ResolvedIpOutsideAllowedCidrException::class);
        (new DefaultTrustedEndpointResolver($policy, $this->dns()))->resolve('localhost');
    }

    public function test_loopback_accepted_only_when_explicitly_allowed(): void
    {
        $policy = new AllowedEndpointHostPolicy(
            exactAllowlist: ['localhost'],
            allowedCidrs: ['127.0.0.0/8'],
            reserved: new ReservedRangePolicy(allowLoopback: true, allowNetworkAndBroadcastAddress: true),
        );
        $this->dnsMap['localhost'] = ['127.0.0.1'];

        $this->assertSame(
            '127.0.0.1',
            (new DefaultTrustedEndpointResolver($policy, $this->dns()))->resolve('localhost')->validatedIp
        );
    }

    // 10. Metadata / link-local / multicast / unspecified rejected.
    public function test_cloud_metadata_address_rejected(): void
    {
        $policy = new AllowedEndpointHostPolicy(
            exactAllowlist: ['meta.alias'],
            allowedCidrs: ['169.254.0.0/16'],
        );
        $this->dnsMap['meta.alias'] = ['169.254.169.254'];

        $this->expectException(ResolvedIpOutsideAllowedCidrException::class);
        (new DefaultTrustedEndpointResolver($policy, $this->dns()))->resolve('meta.alias');
    }

    // 11. Public IP rejected when CIDR only allows control-plane subnet.
    public function test_public_ip_rejected_when_only_local_cidr_allowed(): void
    {
        $this->dnsMap['apntalk-asterisk-node-a-app'] = ['8.8.8.8'];

        $this->expectException(ResolvedIpOutsideAllowedCidrException::class);
        $this->resolver()->resolve('apntalk-asterisk-node-a-app');
    }

    // bridge/gateway explicit denial.
    public function test_explicitly_denied_gateway_address_rejected(): void
    {
        $policy = new AllowedEndpointHostPolicy(
            exactAllowlist: ['apntalk-asterisk-app'],
            allowedCidrs: ['172.30.10.0/24'],
            reserved: new ReservedRangePolicy(deniedIps: ['172.30.10.1']),
        );
        $this->dnsMap['apntalk-asterisk-app'] = ['172.30.10.1'];

        $this->expectException(ResolvedIpOutsideAllowedCidrException::class);
        (new DefaultTrustedEndpointResolver($policy, $this->dns()))->resolve('apntalk-asterisk-app');
    }

    // network / broadcast boundary rejected by default.
    public function test_network_and_broadcast_addresses_rejected(): void
    {
        $this->dnsMap['apntalk-asterisk-node-a-app'] = ['172.30.10.0'];
        try {
            $this->resolver()->resolve('apntalk-asterisk-node-a-app');
            $this->fail('network address should be rejected');
        } catch (ResolvedIpOutsideAllowedCidrException) {
            $this->addToAssertionCount(1);
        }

        $this->dnsMap['apntalk-asterisk-node-a-app'] = ['172.30.10.255'];
        $this->expectException(ResolvedIpOutsideAllowedCidrException::class);
        $this->resolver()->resolve('apntalk-asterisk-node-a-app');
    }

    // 12. Policy enabled but no host trust → ResolverUnavailableException at build.
    public function test_policy_without_allowlist_is_unavailable(): void
    {
        $this->expectException(ResolverUnavailableException::class);
        AllowedEndpointHostPolicy::fromArray([
            'allowed_cidrs' => ['172.30.10.0/24'],
        ]);
    }

    public function test_policy_without_cidr_is_unavailable(): void
    {
        $this->expectException(ResolverUnavailableException::class);
        AllowedEndpointHostPolicy::fromArray([
            'exact_allowlist' => ['apntalk-asterisk-app'],
        ]);
    }

    // Invalid pattern / CIDR are configuration errors.
    public function test_invalid_pattern_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new AllowedEndpointHostPolicy(allowlistPatterns: ['/(unterminated']);
    }

    public function test_invalid_cidr_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new AllowedEndpointHostPolicy(allowedCidrs: ['not-a-cidr']);
    }

    // Every new endpoint-trust exception is an InvalidConfigurationException (BC).
    public function test_all_endpoint_exceptions_extend_invalid_configuration(): void
    {
        foreach ([
            UntrustedEndpointHostException::forHost('x'),
            EndpointHostUnresolvableException::forHost('x'),
            ResolvedIpOutsideAllowedCidrException::forIp('8.8.8.8', 'x', 'reason'),
            AmbiguousEndpointResolutionException::forHost('x', ['1.1.1.1', '2.2.2.2'], 'reason'),
            ResolverUnavailableException::forReason('reason'),
        ] as $exception) {
            $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        }
    }
}
