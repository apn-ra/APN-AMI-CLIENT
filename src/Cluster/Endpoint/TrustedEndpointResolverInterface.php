<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

use Apn\AmiClient\Exceptions\InvalidConfigurationException;

/**
 * A trusted endpoint resolver turns a configured endpoint host (literal IP or
 * trusted hostname) into a validated literal IP for the connection layer.
 *
 * Implementations MUST be fail-closed: any uncertainty MUST throw an
 * {@see InvalidConfigurationException} subclass rather than returning an
 * unvalidated value. Resolution happens at bootstrap/config time, never inside
 * the non-blocking tick path.
 */
interface TrustedEndpointResolverInterface
{
    /**
     * @throws InvalidConfigurationException when the host is untrusted,
     *         unresolvable, resolves outside policy, or is ambiguous.
     */
    public function resolve(string $host): EndpointResolutionResult;
}
