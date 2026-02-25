<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;

/**
 * AMI SetVar action.
 */
final readonly class SetVar extends Action
{
    public function __construct(
        string $variable,
        string $value,
        ?string $channel = null,
        array $parameters = [],
        ?string $actionId = null,
        ?CompletionStrategyInterface $strategy = null,
    ) {
        $parameters['Variable'] = $variable;
        $parameters['Value'] = $value;
        if ($channel !== null) {
            $parameters['Channel'] = $channel;
        }

        parent::__construct($parameters, $actionId, $strategy);
    }

    public function getActionName(): string
    {
        return 'SetVar';
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            variable: $this->parameters['Variable'],
            value: $this->parameters['Value'],
            parameters: $this->parameters,
            actionId: $actionId,
            strategy: $this->strategy
        );
    }
}
