<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Correlation\Strategies\SingleResponseStrategy;

/**
 * AMI Login action.
 */
readonly class Login extends Action
{
    public function __construct(
        string $username,
        #[\SensitiveParameter] string $secret,
        array $parameters = [],
        ?string $actionId = null
    ) {
        $parameters['Username'] = $username;
        $parameters['Secret'] = $secret;
        
        parent::__construct($parameters, $actionId);
    }

    public function getActionName(): string
    {
        return 'Login';
    }

    public function getCompletionStrategy(): CompletionStrategyInterface
    {
        return new SingleResponseStrategy();
    }

    public function withActionId(string $actionId): static
    {
        // Re-extract username and secret from parameters to keep them in the right place
        // although they are already in $this->parameters.
        return new self(
            $this->parameters['Username'],
            $this->parameters['Secret'],
            $this->parameters,
            $actionId
        );
    }
}
