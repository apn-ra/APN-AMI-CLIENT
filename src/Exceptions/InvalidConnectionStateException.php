<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when an action is attempted while the client is not READY.
 */
class InvalidConnectionStateException extends AmiException
{
    public function __construct(
        private readonly string $serverKey,
        private readonly string $state,
        string $message = 'Cannot send action while connection is not READY.'
    ) {
        parent::__construct($message);
    }

    public function getServerKey(): string
    {
        return $this->serverKey;
    }

    public function getState(): string
    {
        return $this->state;
    }
}
