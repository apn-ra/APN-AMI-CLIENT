<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Protocol\Strategies\MultiResponseStrategy;

/**
 * AMI QueueSummary action.
 *
 * Retrieves the summary of one or all queues.
 */
final readonly class QueueSummary extends Action
{
    /**
     * @param string|null $queue The name of the queue to get summary for. If null, all queues are returned.
     * @param string|null $actionId Optional ActionID.
     */
    public function __construct(
        ?string $queue = null,
        ?string $actionId = null
    ) {
        $parameters = [];
        if ($queue !== null) {
            $parameters['Queue'] = $queue;
        }

        parent::__construct(
            $parameters,
            $actionId,
            new MultiResponseStrategy('QueueSummaryComplete')
        );
    }

    public function getActionName(): string
    {
        return 'QueueSummary';
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            $this->parameters['Queue'] ?? null,
            $actionId
        );
    }
}
