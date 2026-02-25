<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Routing;

use Apn\AmiClient\Cluster\Contracts\RoutingStrategyInterface;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Exceptions\AmiException;

/**
 * Routes actions to a specifically named server.
 */
class ExplicitRoutingStrategy implements RoutingStrategyInterface
{
    public function __construct(
        private readonly string $serverKey
    ) {
    }

    /**
     * @inheritDoc
     */
    public function select(array $clients): AmiClientInterface
    {
        if (!isset($clients[$this->serverKey])) {
            throw new AmiException(sprintf("Server '%s' not found in cluster", $this->serverKey));
        }

        $client = $clients[$this->serverKey];

        if (!$client->getHealthStatus()->isAvailable()) {
            throw new AmiException(sprintf("Server '%s' is not available (Status: %s)", $this->serverKey, $client->getHealthStatus()->value));
        }

        return $client;
    }
}
