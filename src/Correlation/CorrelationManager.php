<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Response;

/**
 * Orchestrates ActionID generation and correlation of messages to actions.
 */
class CorrelationManager
{
    public function __construct(
        private readonly ActionIdGenerator $generator,
        private readonly CorrelationRegistry $registry
    ) {
    }

    /**
     * Generates a new unique ActionID.
     */
    public function nextActionId(): string
    {
        return $this->generator->next();
    }

    /**
     * Registers an action and returns a PendingAction object.
     */
    public function register(Action $action): PendingAction
    {
        return $this->registry->register($action);
    }

    /**
     * Handles an incoming response.
     */
    public function handleResponse(Response $response): void
    {
        $this->registry->handleResponse($response);
    }

    /**
     * Handles an incoming event.
     */
    public function handleEvent(Event $event): void
    {
        $this->registry->handleEvent($event);
    }

    /**
     * Performs a timeout sweep.
     */
    public function sweep(): void
    {
        $this->registry->sweep();
    }

    /**
     * Fails all pending actions.
     */
    public function failAll(string $reason): void
    {
        $this->registry->failAll($reason);
    }

    /**
     * Returns the count of pending actions.
     */
    public function count(): int
    {
        return $this->registry->count();
    }
}
