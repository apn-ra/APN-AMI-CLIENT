<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Contracts;

use Apn\AmiClient\Core\Contracts\AmiClientInterface;

/**
 * Interface for action routing strategies across multiple servers.
 */
interface RoutingStrategyInterface
{
    /**
     * Select a client from the provided list based on the strategy.
     *
     * @param array<string, AmiClientInterface> $clients
     * @return AmiClientInterface
     * @throws \Apn\AmiClient\Exceptions\AmiException If no suitable client can be selected.
     */
    public function select(array $clients): AmiClientInterface;
}
