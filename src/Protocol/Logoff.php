<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;

/**
 * Logoff action to cleanly disconnect from AMI.
 */
final readonly class Logoff extends Action
{
    public function getActionName(): string
    {
        return 'Logoff';
    }

    public function withActionId(string $actionId): static
    {
        return new self($this->parameters, $actionId, $this->strategy);
    }
}
