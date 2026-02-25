<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

/**
 * Configuration for a single Asterisk server.
 */
readonly class ServerConfig
{
    public function __construct(
        public string $key,
        public string $host,
        public int $port = 5038,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $secret = null,
        public ?ClientOptions $options = null,
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(string $key, array $config): self
    {
        // Extract options, potentially merging top-level overrides
        $optionsArr = $config['options'] ?? [];
        if (isset($config['timeout'])) {
            $optionsArr['connect_timeout'] = $config['timeout'];
        }
        if (isset($config['write_buffer_limit'])) {
            $optionsArr['write_buffer_limit'] = $config['write_buffer_limit'];
        }

        return new self(
            key: $key,
            host: $config['host'] ?? throw new \InvalidArgumentException("Missing host for server $key"),
            port: $config['port'] ?? 5038,
            username: $config['username'] ?? null,
            secret: $config['secret'] ?? null,
            options: !empty($optionsArr) ? ClientOptions::fromArray($optionsArr) : null
        );
    }
}
