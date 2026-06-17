<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\ConfigLoader;
use Apn\AmiClient\Cluster\Endpoint\AllowedEndpointHostPolicy;
use Apn\AmiClient\Cluster\Endpoint\DefaultTrustedEndpointResolver;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Exceptions\ResolverUnavailableException;
use Apn\AmiClient\Exceptions\UntrustedEndpointHostException;
use PHPUnit\Framework\TestCase;

/**
 * Manager / ConfigLoader wiring for the trusted endpoint resolver. No sockets:
 * the manager resolves endpoints at construction time, so a successful (or
 * failing) bootstrap is sufficient. A DNS hook keeps everything offline.
 */
final class TrustedEndpointResolverConfigTest extends TestCase
{
    private function dns(): callable
    {
        return static fn (string $host): array => match ($host) {
            'apntalk-asterisk-app' => ['172.30.10.20'],
            'apntalk-asterisk-node-a-app' => ['10.250.5.7'],
            'apntalk-asterisk-node-b-app' => ['203.0.113.9'], // out of CIDR (documentation/public)
            default => [],
        };
    }

    private function trustedPolicyConfig(): array
    {
        return [
            'exact_allowlist' => ['apntalk-asterisk-app'],
            'allowlist_patterns' => ['/^apntalk-asterisk-[a-z0-9-]+-app$/'],
            'allowed_cidrs' => ['172.30.10.0/24', '10.250.0.0/16'],
            'multi_ip' => 'reject',
        ];
    }

    private function literalIpOf(\Apn\AmiClient\Cluster\AmiClientManager $manager, string $serverKey): string
    {
        $client = $manager->server($serverKey);
        $this->assertInstanceOf(AmiClient::class, $client);
        $transport = $client->getTransport();

        $ref = new \ReflectionProperty($transport, 'remoteHost');

        return (string) $ref->getValue($transport);
    }

    // 13. enforce=true + installed policy → trusted alias accepted (no enforce-off).
    public function test_enforce_true_with_policy_accepts_trusted_alias(): void
    {
        $config = [
            'default' => 'node1',
            'options' => ['enforce_ip_endpoints' => true],
            'trusted_endpoint_policy' => $this->trustedPolicyConfig(),
            'servers' => [
                'node1' => ['host' => 'apntalk-asterisk-app', 'port' => 5038],
                'node2' => ['host' => 'apntalk-asterisk-node-a-app', 'port' => 5038],
            ],
        ];

        $manager = ConfigLoader::load($config, dnsResolver: $this->dns());

        // 16 / 2.4: transport receives a literal validated IP, never the hostname.
        $this->assertSame('172.30.10.20', $this->literalIpOf($manager, 'node1'));
        $this->assertSame('10.250.5.7', $this->literalIpOf($manager, 'node2'));
    }

    // 14. enforce=true + NO policy → hostname rejected (literal-IP-only regression guard).
    public function test_enforce_true_without_policy_rejects_hostname(): void
    {
        $config = [
            'default' => 'node1',
            'options' => ['enforce_ip_endpoints' => true],
            'servers' => [
                'node1' => ['host' => 'apntalk-asterisk-app', 'port' => 5038],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('enforce_ip_endpoints is enabled');
        ConfigLoader::load($config);
    }

    // Trusted policy still rejects an untrusted host even with a policy installed.
    public function test_untrusted_host_rejected_with_policy_installed(): void
    {
        $config = [
            'options' => ['enforce_ip_endpoints' => true],
            'trusted_endpoint_policy' => $this->trustedPolicyConfig(),
            'servers' => [
                'node1' => ['host' => 'evil.example.com', 'port' => 5038],
            ],
        ];

        $this->expectException(UntrustedEndpointHostException::class);
        ConfigLoader::load($config, dnsResolver: $this->dns());
    }

    // Trusted alias resolving outside CIDR is rejected at bootstrap.
    public function test_trusted_alias_out_of_cidr_rejected(): void
    {
        $config = [
            'options' => ['enforce_ip_endpoints' => true],
            'trusted_endpoint_policy' => $this->trustedPolicyConfig(),
            'servers' => [
                'node1' => ['host' => 'apntalk-asterisk-node-b-app', 'port' => 5038],
            ],
        ];

        $this->expectException(\Apn\AmiClient\Exceptions\ResolvedIpOutsideAllowedCidrException::class);
        ConfigLoader::load($config, dnsResolver: $this->dns());
    }

    // 15. Resolver returning a non-IP is contained (defaults filter to valid IPs → unresolvable).
    public function test_resolver_returning_non_ip_is_contained(): void
    {
        $config = [
            'options' => ['enforce_ip_endpoints' => true],
            'trusted_endpoint_policy' => $this->trustedPolicyConfig(),
            'servers' => [
                'node1' => ['host' => 'apntalk-asterisk-app', 'port' => 5038],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);
        ConfigLoader::load($config, dnsResolver: static fn (string $h): array => ['not-an-ip', '']);
    }

    // 17. Unsafe state: enforce=false + no policy/resolver + hostname → rejected (fail-closed).
    public function test_enforce_false_without_policy_or_resolver_rejected(): void
    {
        $config = [
            'options' => ['enforce_ip_endpoints' => false],
            'servers' => [
                'node1' => ['host' => 'apntalk-asterisk-app', 'port' => 5038],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('pre-resolved IP or an injected hostname resolver');
        ConfigLoader::load($config);
    }

    // Declared-but-unusable policy fails closed at load time.
    public function test_declared_policy_without_trust_is_unavailable_at_load(): void
    {
        $config = [
            'options' => ['enforce_ip_endpoints' => true],
            'trusted_endpoint_policy' => ['allowed_cidrs' => ['172.30.10.0/24']],
            'servers' => [
                'node1' => ['host' => '172.30.10.20', 'port' => 5038],
            ],
        ];

        $this->expectException(ResolverUnavailableException::class);
        ConfigLoader::load($config, dnsResolver: $this->dns());
    }

    // An explicitly injected resolver instance is honored.
    public function test_explicit_resolver_instance_is_used(): void
    {
        $policy = new AllowedEndpointHostPolicy(
            exactAllowlist: ['apntalk-asterisk-app'],
            allowedCidrs: ['172.30.10.0/24'],
        );
        $resolver = new DefaultTrustedEndpointResolver($policy, $this->dns());

        $config = [
            'options' => ['enforce_ip_endpoints' => true],
            'servers' => [
                'node1' => ['host' => 'apntalk-asterisk-app', 'port' => 5038],
            ],
        ];

        $manager = ConfigLoader::load($config, trustedResolver: $resolver);
        $this->assertSame('172.30.10.20', $this->literalIpOf($manager, 'node1'));
    }

    // Literal IP endpoints still work unchanged under the default strict policy.
    public function test_literal_ip_endpoint_still_accepted_by_default(): void
    {
        $config = [
            'options' => ['enforce_ip_endpoints' => true],
            'servers' => [
                'node1' => ['host' => '172.30.10.20', 'port' => 5038],
            ],
        ];

        $manager = ConfigLoader::load($config);
        $this->assertSame('172.30.10.20', $this->literalIpOf($manager, 'node1'));
    }
}
