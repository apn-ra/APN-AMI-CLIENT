<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Routing;

use Apn\AmiClient\Cluster\Contracts\RoutingStrategyInterface;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Exceptions\AmiException;

/**
 * Routes actions to servers in a round-robin fashion, skipping disconnected nodes.
 */
class RoundRobinRoutingStrategy implements RoutingStrategyInterface
{
    private int $lastIndex = -1;

    /**
     * @inheritDoc
     */
    public function select(array $clients): AmiClientInterface
    {
        if (empty($clients)) {
            throw new AmiException("No servers available for routing");
        }

        $keys = array_keys($clients);
        $count = count($keys);

        // Try to find a connected client starting from the next index
        for ($i = 0; $i < $count; $i++) {
            $this->lastIndex = ($this->lastIndex + 1) % $count;
            $client = $clients[$keys[$this->lastIndex]];

            if ($client->getHealthStatus()->isAvailable()) {
                return $client;
            }
        }

        // If no connected client is found, return the first one as a fallback or throw exception
        // The task says "exclude Disconnected or Degraded nodes"
        throw new AmiException("No healthy servers available for routing");
    }
}
