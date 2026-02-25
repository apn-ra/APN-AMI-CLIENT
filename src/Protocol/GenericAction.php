<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Correlation\Strategies\SingleResponseStrategy;

/**
 * Generic AMI Action supporting arbitrary headers and parameters.
 */
final readonly class GenericAction extends Action
{
    /**
     * @param string $actionName The AMI Action name (e.g. "Status", "QueueSummary")
     * @param array<string, string|array<int, string>> $parameters Additional AMI headers.
     * @param string|null $actionId Optional pre-defined ActionID.
     * @param CompletionStrategyInterface|null $strategy Optional completion strategy.
     */
    public function __construct(
        private string $actionName,
        array $parameters = [],
        ?string $actionId = null,
        private ?CompletionStrategyInterface $strategy = null
    ) {
        parent::__construct($parameters, $actionId);
    }

    /**
     * @inheritDoc
     */
    public function getActionName(): string
    {
        return $this->actionName;
    }

    /**
     * @inheritDoc
     */
    public function getCompletionStrategy(): CompletionStrategyInterface
    {
        return $this->strategy ?? new SingleResponseStrategy();
    }

    /**
     * @inheritDoc
     */
    public function withActionId(string $actionId): static
    {
        return new self(
            $this->actionName,
            $this->parameters,
            $actionId,
            $this->strategy
        );
    }
}
