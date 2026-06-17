<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

use Apn\AmiClient\Cluster\Endpoint\AllowedEndpointHostPolicy;
use Apn\AmiClient\Cluster\Endpoint\DefaultTrustedEndpointResolver;
use Apn\AmiClient\Cluster\Endpoint\TrustedEndpointResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Helper to bootstrap AmiClientManager from configuration arrays.
 */
final class ConfigLoader
{
    /**
     * Bootstraps an AmiClientManager from a configuration array.
     *
     * Trusted-hostname support (RB-ENFORCE-IP, Authority A) is opt-in and
     * additive:
     *  - Pass an explicit {@see TrustedEndpointResolverInterface} via
     *    $trustedResolver, OR
     *  - Declare `config['trusted_endpoint_policy']` and (optionally) inject a
     *    DNS callable via $dnsResolver; this builds a
     *    {@see DefaultTrustedEndpointResolver}.
     *
     * When neither is provided, behavior is unchanged: literal-IP-only under
     * enforce_ip_endpoints=true. The legacy $hostnameResolver callable is
     * retained for backward compatibility (enforce_ip_endpoints=false path).
     *
     * @param array<string, mixed> $config
     * @param (callable(string): string)|null $hostnameResolver Legacy callable (BC).
     * @param (callable(string): array<int, string>)|null $dnsResolver DNS hook used only when
     *        building a resolver from config['trusted_endpoint_policy'].
     */
    public static function load(
        array $config,
        ?LoggerInterface $logger = null,
        ?callable $hostnameResolver = null,
        ?TrustedEndpointResolverInterface $trustedResolver = null,
        ?callable $dnsResolver = null
    ): AmiClientManager {
        $globalOptionsArr = $config['options'] ?? [];
        $globalOptions = ClientOptions::fromArray($globalOptionsArr);

        $registry = new ServerRegistry();
        $servers = $config['servers'] ?? [];

        foreach ($servers as $key => $serverConfigArr) {
            $registry->add(ServerConfig::fromArray((string)$key, $serverConfigArr));
        }

        // An explicitly injected resolver wins; otherwise build one from config
        // if a trusted endpoint policy is declared. Building from config is
        // fail-closed: a declared-but-unusable policy throws here, at load time.
        if ($trustedResolver === null && isset($config['trusted_endpoint_policy'])) {
            $policyConfig = $config['trusted_endpoint_policy'];
            if (!is_array($policyConfig)) {
                throw new \Apn\AmiClient\Exceptions\InvalidConfigurationException(
                    'trusted_endpoint_policy must be an array of policy settings.'
                );
            }

            $policy = AllowedEndpointHostPolicy::fromArray($policyConfig);
            $trustedResolver = new DefaultTrustedEndpointResolver($policy, $dnsResolver);
        }

        $manager = new AmiClientManager(
            $registry,
            $globalOptions,
            $logger,
            hostnameResolver: $hostnameResolver,
            trustedResolver: $trustedResolver
        );

        if (isset($config['default'])) {
            $manager->setDefaultServer((string)$config['default']);
        }

        return $manager;
    }
}
