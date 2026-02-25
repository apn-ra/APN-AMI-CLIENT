<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;

/**
 * AMI GetVar action.
 */
final readonly class GetVar extends Action
{
    public function __construct(
        string $variable,
        ?string $channel = null,
        array $parameters = [],
        ?string $actionId = null,
        ?CompletionStrategyInterface $strategy = null,
    ) {
        $parameters['Variable'] = $variable;
        if ($channel !== null) {
            $parameters['Channel'] = $channel;
        }

        parent::__construct($parameters, $actionId, $strategy);
    }

    public function getActionName(): string
    {
        return 'GetVar';
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            variable: $this->parameters['Variable'],
            parameters: $this->parameters,
            actionId: $actionId,
            strategy: $this->strategy
        );
    }
}
