<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Correlation\Strategies\SingleResponseStrategy;

/**
 * Represents an AMI Originate action.
 */
final readonly class Originate extends Action
{
    public function __construct(
        string $channel,
        ?string $exten = null,
        ?string $context = null,
        ?int $priority = null,
        ?string $application = null,
        ?string $data = null,
        ?int $timeout = null,
        ?string $callerId = null,
        array $variables = [],
        ?string $account = null,
        bool $async = true,
        ?string $channelId = null,
        ?string $otherChannelId = null,
        array $parameters = [],
        ?string $actionId = null
    ) {
        $parameters['Channel'] = $channel;
        
        if ($exten !== null) {
            $parameters['Exten'] = $exten;
        }
        if ($context !== null) {
            $parameters['Context'] = $context;
        }
        if ($priority !== null) {
            $parameters['Priority'] = (string)$priority;
        }
        if ($application !== null) {
            $parameters['Application'] = $application;
        }
        if ($data !== null) {
            $parameters['Data'] = $data;
        }
        if ($timeout !== null) {
            $parameters['Timeout'] = (string)$timeout;
        }
        if ($callerId !== null) {
            $parameters['CallerID'] = $callerId;
        }
        if ($account !== null) {
            $parameters['Account'] = $account;
        }
        
        $parameters['Async'] = $async ? 'true' : 'false';
        
        if ($channelId !== null) {
            $parameters['ChannelID'] = $channelId;
        }
        if ($otherChannelId !== null) {
            $parameters['OtherChannelID'] = $otherChannelId;
        }

        foreach ($variables as $key => $value) {
            // AMI supports multiple Variable headers or semicolon separated
            // The parser/serializer handles array of values for the same key.
            $parameters['Variable'][] = "$key=$value";
        }

        parent::__construct($parameters, $actionId);
    }

    public function getActionName(): string
    {
        return 'Originate';
    }

    public function getCompletionStrategy(): CompletionStrategyInterface
    {
        return new SingleResponseStrategy();
    }

    public function withActionId(string $actionId): static
    {
        return new self(
            channel: $this->parameters['Channel'],
            parameters: $this->parameters,
            actionId: $actionId
        );
    }
}
