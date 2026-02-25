<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Protocol\Strategies\MultiResponseStrategy;

/**
 * AMI QueueStatus action.
 *
 * Retrieves the status of one or all queues.
 */
final readonly class QueueStatus extends Action
{
    /**
     * @param string|null $queue The name of the queue to get status for. If null, all queues are returned.
     * @param string|null $member The name of the member to get status for.
     * @param string|null $actionId Optional ActionID.
     */
    public function __construct(
        ?string $queue = null,
        ?string $member = null,
        ?string $actionId = null
    ) {
        $parameters = [];
        if ($queue !== null) {
            $parameters['Queue'] = $queue;
        }
        if ($member !== null) {
            $parameters['Member'] = $member;
        }

        parent::__construct(
            $parameters,
            $actionId,
            new MultiResponseStrategy('QueueStatusComplete')
        );
    }

    public function getActionName(): string
    {
        return 'QueueStatus';
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            $this->parameters['Queue'] ?? null,
            $this->parameters['Member'] ?? null,
            $actionId
        );
    }
}
