<?php

declare(strict_types=1);

namespace Tests\RealPbx;

final readonly class RealPbxConfig
{
    public function __construct(
        public string $serverKey,
        public ?string $host,
        public int $port,
        public ?string $username,
        #[\SensitiveParameter]
        public ?string $secret,
        public bool $tls,
        public int $connectTimeoutMs,
        public int $authTimeoutMs,
    ) {
    }

    public static function fromArray(array $input): self
    {
        return new self(
            serverKey: (string) ($input['server_key'] ?? 'pbx01'),
            host: isset($input['host']) && is_string($input['host']) ? trim($input['host']) : null,
            port: (int) ($input['port'] ?? 5038),
            username: isset($input['username']) && is_string($input['username']) ? trim($input['username']) : null,
            secret: isset($input['secret']) && is_string($input['secret']) ? $input['secret'] : null,
            tls: (bool) ($input['tls'] ?? false),
            connectTimeoutMs: max(100, (int) ($input['connect_timeout_ms'] ?? 2000)),
            authTimeoutMs: max(100, (int) ($input['auth_timeout_ms'] ?? 2000)),
        );
    }

    public function hasCredentials(): bool
    {
        return $this->host !== null
            && $this->host !== ''
            && $this->username !== null
            && $this->username !== ''
            && $this->secret !== null
            && $this->secret !== '';
    }

    /**
     * @return list<string>
     */
    public function missingCredentialKeys(): array
    {
        $missing = [];

        if ($this->host === null || $this->host === '') {
            $missing[] = 'AMI_HOST';
        }
        if ($this->username === null || $this->username === '') {
            $missing[] = 'AMI_USERNAME';
        }
        if ($this->secret === null || $this->secret === '') {
            $missing[] = 'AMI_SECRET';
        }

        return $missing;
    }
}
