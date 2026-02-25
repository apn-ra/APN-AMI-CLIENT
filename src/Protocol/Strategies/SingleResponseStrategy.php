<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol\Strategies;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;

/**
 * Strategy for actions that complete upon receiving a single response.
 */
final class SingleResponseStrategy implements CompletionStrategyInterface
{
    private bool $complete = false;

    public function onResponse(Response $response): bool
    {
        $this->complete = true;
        return true;
    }

    public function onEvent(Event $event): bool
    {
        // SingleResponseStrategy does not wait for events.
        return false;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
