<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;

/**
 * Base class for all outgoing AMI actions.
 */
abstract readonly class Action
{
    /**
     * @param array<string, string|array<int, string>> $parameters Additional AMI headers.
     */
    public function __construct(
        protected array $parameters = [],
        protected ?string $actionId = null,
    ) {
    }

    /**
     * Get the AMI Action name.
     */
    abstract public function getActionName(): string;

    /**
     * Get the completion strategy for this action.
     */
    abstract public function getCompletionStrategy(): CompletionStrategyInterface;

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
