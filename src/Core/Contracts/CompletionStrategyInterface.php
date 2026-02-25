<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core\Contracts;

use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;

/**
 * Defines how an Action's completion is determined.
 */
interface CompletionStrategyInterface
{
    /**
     * Called when a response is received for the action.
     * Returns true if the action is complete.
     */
    public function onResponse(Response $response): bool;

    /**
     * Called when an event is received that might relate to the action.
     * Returns true if the action is complete.
     */
    public function onEvent(Event $event): bool;

    /**
     * Whether this action is complete.
     */
    public function isComplete(): bool;
}
