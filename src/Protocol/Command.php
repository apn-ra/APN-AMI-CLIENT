<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Protocol\Strategies\FollowsResponseStrategy;

/**
 * Command action specifically for the Asterisk "Command" AMI action.
 */
final readonly class Command extends Action
{
    /**
     * @param string $command The Asterisk CLI command (e.g. "core show channels")
     * @param string|null $actionId Optional pre-defined ActionID.
     * @param CompletionStrategyInterface|null $strategy Optional completion strategy.
     */
    public function __construct(
        private string $command,
        ?string $actionId = null,
        ?CompletionStrategyInterface $strategy = null,
    ) {
        parent::__construct([], $actionId, $strategy);
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
        return $this->strategy ?? new FollowsResponseStrategy();
    }

    /**
     * @inheritDoc
     */
    public function withActionId(string $actionId): static
    {
        return new self($this->command, $actionId, $this->strategy);
    }
}
