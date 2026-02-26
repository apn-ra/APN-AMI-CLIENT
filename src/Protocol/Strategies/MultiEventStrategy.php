<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol\Strategies;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;

/**
 * Strategy for actions that return a success response followed by a sequence of events,
 * finishing with a specific "Complete" event.
 */
final class MultiEventStrategy implements CompletionStrategyInterface
{
    private bool $complete = false;

    /**
     * @param string $completeEventName The name of the event that signals completion.
     * @param int $maxDurationMs Maximum duration for this action.
     * @param int $maxMessages Maximum messages to collect.
     */
    public function __construct(
        private readonly string $completeEventName,
        private readonly int $maxDurationMs = 60000,
        private readonly int $maxMessages = 10000,
    ) {
    }

    public function onResponse(Response $response): bool
    {
        // If it's an error response, we don't expect any following events.
        if (!$response->isSuccess()) {
            $this->complete = true;
            return true;
        }

        // Action is not yet complete; waiting for the completion event.
        return false;
    }

    public function onEvent(Event $event): bool
    {
        if (strcasecmp($event->getName(), $this->completeEventName) === 0) {
            $this->complete = true;
            return true;
        }

        return false;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function getMaxDurationMs(): int
    {
        return $this->maxDurationMs;
    }

    public function getMaxMessages(): int
    {
        return $this->maxMessages;
    }

    public function getTerminalEventNames(): array
    {
        return [$this->completeEventName];
    }
}
