<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

/**
 * Represents the initial banner sent by Asterisk upon connection.
 * e.g., "Asterisk Call Manager/5.0.1"
 */
final readonly class Banner extends Message
{
    public function __construct(
        private string $versionString,
    ) {
        parent::__construct(['banner' => $versionString]);
    }

    /**
     * Get the full banner version string.
     */
    public function getVersionString(): string
    {
        return $this->versionString;
    }
}
