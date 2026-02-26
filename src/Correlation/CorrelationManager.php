<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Response;
use Throwable;

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
     * Rejects and removes a pending action if still registered.
     */
    public function rollback(string $actionId, Throwable $exception): bool
    {
        return $this->registry->rollback($actionId, $exception);
    }

    /**
     * Sets callback-exception isolation/reporting handler for pending actions.
     */
    public function setCallbackExceptionHandler(?callable $handler): void
    {
        $this->registry->setCallbackExceptionHandler($handler);
    }

    /**
     * Returns the count of pending actions.
     */
    public function count(): int
    {
        return $this->registry->count();
    }
}
