<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Routing;

use Apn\AmiClient\Cluster\Contracts\RoutingStrategyInterface;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Exceptions\AmiException;

/**
 * Routes actions to the first healthy server in the list.
 */
class FailoverRoutingStrategy implements RoutingStrategyInterface
{
    /**
     * @inheritDoc
     */
    public function select(array $clients): AmiClientInterface
    {
        if (empty($clients)) {
            throw new AmiException("No servers available for routing");
        }

        foreach ($clients as $client) {
            if ($client->getHealthStatus()->isAvailable()) {
                return $client;
            }
        }

        throw new AmiException("No healthy servers available for routing");
    }
}
