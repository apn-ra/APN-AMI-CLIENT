<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;

/**
 * AMI Redirect action.
 */
final readonly class Redirect extends Action
{
    /**
     * @param string $channel The channel to redirect.
     * @param string $exten The destination extension.
     * @param string $context The destination context.
     * @param int|string $priority The destination priority.
     * @param string|null $extraChannel The second channel to redirect (optional).
     * @param string|null $extraExten The second destination extension (optional).
     * @param string|null $extraContext The second destination context (optional).
     * @param int|string|null $extraPriority The second destination priority (optional).
     * @param array $parameters Additional AMI headers.
     * @param string|null $actionId Optional ActionID.
     * @param CompletionStrategyInterface|null $strategy Optional completion strategy.
     */
    public function __construct(
        string $channel,
        string $exten,
        string $context,
        int|string $priority,
        ?string $extraChannel = null,
        ?string $extraExten = null,
        ?string $extraContext = null,
        int|string|null $extraPriority = null,
        array $parameters = [],
        ?string $actionId = null,
        ?CompletionStrategyInterface $strategy = null,
    ) {
        $parameters['Channel'] = $channel;
        $parameters['Exten'] = $exten;
        $parameters['Context'] = $context;
        $parameters['Priority'] = (string)$priority;

        if ($extraChannel !== null) {
            $parameters['ExtraChannel'] = $extraChannel;
        }
        if ($extraExten !== null) {
            $parameters['ExtraExten'] = $extraExten;
        }
        if ($extraContext !== null) {
            $parameters['ExtraContext'] = $extraContext;
        }
        if ($extraPriority !== null) {
            $parameters['ExtraPriority'] = (string)$extraPriority;
        }

        parent::__construct($parameters, $actionId, $strategy);
    }

    public function getActionName(): string
    {
        return 'Redirect';
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            channel: $this->parameters['Channel'],
            exten: $this->parameters['Exten'],
            context: $this->parameters['Context'],
            priority: $this->parameters['Priority'],
            extraChannel: $this->parameters['ExtraChannel'] ?? null,
            extraExten: $this->parameters['ExtraExten'] ?? null,
            extraContext: $this->parameters['ExtraContext'] ?? null,
            extraPriority: $this->parameters['ExtraPriority'] ?? null,
            parameters: $this->parameters,
            actionId: $actionId,
            strategy: $this->strategy
        );
    }
}
