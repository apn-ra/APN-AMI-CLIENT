<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Protocol\Strategies\MultiEventStrategy;

/**
 * AMI PJSIPShowEndpoints action.
 *
 * Lists all PJSIP endpoints.
 */
final readonly class PJSIPShowEndpoints extends Action
{
    /**
     * @param string|null $actionId Optional ActionID.
     */
    public function __construct(
        ?string $actionId = null
    ) {
        parent::__construct(
            [],
            $actionId,
            new MultiEventStrategy('EndpointListComplete')
        );
    }

    public function getActionName(): string
    {
        return 'PJSIPShowEndpoints';
    }

    public function withActionId(string $actionId): static
    {
        return new self($actionId);
    }
}
