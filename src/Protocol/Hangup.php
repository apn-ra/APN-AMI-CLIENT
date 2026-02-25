<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;

/**
 * AMI Hangup action.
 */
final readonly class Hangup extends Action
{
    /**
     * @param string $channel The channel to hang up.
     * @param int|null $cause The numeric hangup cause.
     * @param array $parameters Additional AMI headers.
     * @param string|null $actionId Optional ActionID.
     * @param CompletionStrategyInterface|null $strategy Optional completion strategy.
     */
    public function __construct(
        string $channel,
        ?int $cause = null,
        array $parameters = [],
        ?string $actionId = null,
        ?CompletionStrategyInterface $strategy = null,
    ) {
        $parameters['Channel'] = $channel;
        if ($cause !== null) {
            $parameters['Cause'] = (string)$cause;
        }

        parent::__construct($parameters, $actionId, $strategy);
    }

    public function getActionName(): string
    {
        return 'Hangup';
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            channel: $this->parameters['Channel'],
            cause: isset($this->parameters['Cause']) ? (int)$this->parameters['Cause'] : null,
            parameters: $this->parameters,
            actionId: $actionId,
            strategy: $this->strategy
        );
    }
}
