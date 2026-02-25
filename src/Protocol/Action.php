<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;

use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;

/**
 * Base class for all outgoing AMI actions.
 */
abstract readonly class Action
{
    /**
     * @param array<string, string|array<int, string>> $parameters Additional AMI headers.
     * @param string|null $actionId Optional ActionID.
     * @param CompletionStrategyInterface|null $strategy Optional completion strategy.
     */
    public function __construct(
        protected array $parameters = [],
        protected ?string $actionId = null,
        protected ?CompletionStrategyInterface $strategy = null,
    ) {
    }

    /**
     * Get the AMI Action name.
     */
    abstract public function getActionName(): string;

    /**
     * Get the completion strategy for this action.
     */
    public function getCompletionStrategy(): CompletionStrategyInterface
    {
        return $this->strategy ?? new SingleResponseStrategy();
    }

    /**
     * Get the ActionID if set.
     */
    public function getActionId(): ?string
    {
        return $this->actionId;
    }

    /**
     * Returns all parameters.
     *
     * @return array<string, string|array<int, string>>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Returns a new instance with the specified ActionID.
     */
    abstract public function withActionId(string $actionId): static;
}
