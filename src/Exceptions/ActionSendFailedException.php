<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

final class ActionSendFailedException extends AmiException
{
    public function __construct(
        private readonly string $serverKey,
        private readonly string $actionId,
        private readonly string $actionName,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getServerKey(): string
    {
        return $this->serverKey;
    }

    public function getActionId(): string
    {
        return $this->actionId;
    }

    public function getActionName(): string
    {
        return $this->actionName;
    }
}
