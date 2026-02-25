<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Correlation\Strategies\SingleResponseStrategy;

/**
 * AMI Ping action.
 */
readonly class Ping extends Action
{
    public function getActionName(): string
    {
        return 'Ping';
    }

    public function getCompletionStrategy(): CompletionStrategyInterface
    {
        return new SingleResponseStrategy();
    }

    public function withActionId(string $actionId): static
    {
        return new self($this->parameters, $actionId);
    }
}
