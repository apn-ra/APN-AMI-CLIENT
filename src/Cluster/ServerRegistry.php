<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

/**
 * Registry of available AMI nodes.
 */
class ServerRegistry
{
    /** @var array<string, ServerConfig> */
    private array $servers = [];

    /**
     * Add a server to the registry.
     */
    public function add(ServerConfig $config): void
    {
        $this->servers[$config->key] = $config;
    }

    /**
     * Returns a server by key.
     */
    public function get(string $key): ServerConfig
    {
        if (!isset($this->servers[$key])) {
            throw new \InvalidArgumentException(sprintf("AMI server '%s' not found in registry", $key));
        }

        return $this->servers[$key];
    }

    /**
     * Returns all registered servers.
     *
     * @return array<string, ServerConfig>
     */
    public function all(): array
    {
        return $this->servers;
    }

    /**
     * Returns the count of registered servers.
     */
    public function count(): int
    {
        return count($this->servers);
    }
}
