<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Correlation\Strategies\FollowsResponseStrategy;

/**
 * Command action specifically for the Asterisk "Command" AMI action.
 */
final readonly class Command extends Action
{
    /**
     * @param string $command The Asterisk CLI command (e.g. "core show channels")
     * @param string|null $actionId Optional pre-defined ActionID.
     */
    public function __construct(
        private string $command,
        ?string $actionId = null
    ) {
        parent::__construct([], $actionId);
    }

    /**
     * @inheritDoc
     */
    public function getActionName(): string
    {
        return 'Command';
    }

    /**
     * @inheritDoc
     */
    public function getParameters(): array
    {
        return ['Command' => $this->command];
    }

    /**
     * @inheritDoc
     */
    public function getCompletionStrategy(): CompletionStrategyInterface
    {
        return new FollowsResponseStrategy();
    }

    /**
     * @inheritDoc
     */
    public function withActionId(string $actionId): static
    {
        return new self($this->command, $actionId);
    }
}
