<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Protocol\Strategies\MultiEventStrategy;

/**
 * AMI PJSIPShowEndpoint action.
 *
 * Shows details for a specific PJSIP endpoint.
 */
final readonly class PJSIPShowEndpoint extends Action
{
    /**
     * @param string $endpoint The name of the endpoint to show.
     * @param string|null $actionId Optional ActionID.
     */
    public function __construct(
        string $endpoint,
        ?string $actionId = null
    ) {
        parent::__construct(
            ['Endpoint' => $endpoint],
            $actionId,
            new MultiEventStrategy('EndpointDetailComplete')
        );
    }

    public function getActionName(): string
    {
        return 'PJSIPShowEndpoint';
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            $this->parameters['Endpoint'],
            $actionId
        );
    }
}
