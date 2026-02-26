<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

use Apn\AmiClient\Exceptions\AmiTimeoutException;
use Apn\AmiClient\Exceptions\ConnectionLostException;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;
use Throwable;

/**
 * Manages pending actions and correlates incoming responses/events to them.
 * Scoped per server connection.
 */
final class CorrelationRegistry
{
    /** @var array<string, PendingAction> */
    private array $pending = [];

    /** @var array<string, Response> */
    private array $responses = [];

    /** @var array<string, Event[]> */
    private array $collectedEvents = [];

    /**
     * @param int $maxPending Hard limit for concurrent pending actions.
     */
    public function __construct(
        private readonly int $maxPending = 5000
    ) {
    }

    /**
     * Registers an action in the registry.
     *
     * @throws BackpressureException if the pending action limit is reached.
     */
    public function register(Action $action, ?float $timeoutSeconds = null): PendingAction
    {
        if (count($this->pending) >= $this->maxPending) {
            throw new BackpressureException(
                sprintf("Pending action limit (%d) reached for this server", $this->maxPending)
            );
        }

        $actionId = $action->getActionId();
        if ($actionId === null) {
            throw new \InvalidArgumentException("Action must have an ActionID for correlation");
        }

        $strategy = $action->getCompletionStrategy();
        $timeoutSeconds ??= $strategy->getMaxDurationMs() / 1000.0;

        $pendingAction = new PendingAction($action, microtime(true) + $timeoutSeconds);
        $this->pending[$actionId] = $pendingAction;
        $this->collectedEvents[$actionId] = [];

        return $pendingAction;
    }

    /**
     * Handles an incoming response from the AMI server.
     */
    public function handleResponse(Response $response): void
    {
        $actionId = $response->getActionId();
        if ($actionId === null || !isset($this->pending[$actionId])) {
            return;
        }

        $pending = $this->pending[$actionId];
        $strategy = $pending->getAction()->getCompletionStrategy();

        if ($strategy->onResponse($response)) {
            $this->complete($actionId, $response);
        } else {
            // Save response, waiting for more messages (events)
            $this->responses[$actionId] = $response;
        }
    }

    /**
     * Handles an incoming event from the AMI server.
     */
    public function handleEvent(Event $event): void
    {
        $actionId = $event->getHeader('actionid');
        if (!is_string($actionId) || !isset($this->pending[$actionId])) {
            return;
        }

        $pending = $this->pending[$actionId];
        $strategy = $pending->getAction()->getCompletionStrategy();

        $maxMessages = $strategy->getMaxMessages();
        if ($maxMessages === 0) {
            $maxMessages = 10000; // Default safety cap
        }

        if (count($this->collectedEvents[$actionId]) < $maxMessages) {
            $this->collectedEvents[$actionId][] = $event;
        }

        if ($strategy->onEvent($event)) {
            $response = $this->responses[$actionId] ?? null;
            $this->complete($actionId, $response);
        }
    }

    /**
     * Performs a timeout sweep and rejects expired actions.
     * Should be called on every tick.
     */
    public function sweep(): void
    {
        $now = microtime(true);
        // We use array_keys to avoid issues with unsetting while iterating
        foreach (array_keys($this->pending) as $actionId) {
            $pending = $this->pending[$actionId];
            if ($pending->isExpired($now)) {
                $this->fail(
                    (string)$actionId,
                    new AmiTimeoutException(sprintf("ActionID %s timed out", $actionId))
                );
            }
        }
    }

    /**
     * Fails all pending actions due to connection loss.
     */
    public function failAll(string $reason = 'Connection lost'): void
    {
        $exception = new ConnectionLostException($reason);
        foreach (array_keys($this->pending) as $actionId) {
            $this->fail((string)$actionId, $exception);
        }
    }

    /**
     * Cleanly completes an action.
     */
    private function complete(string $actionId, ?Response $response): void
    {
        $pending = $this->pending[$actionId];
        $events = $this->collectedEvents[$actionId] ?? [];
        
        $this->cleanup($actionId);

        // Resolve with the provided response, or an empty success response if missing
        $pending->resolve($response ?? new Response(['response' => 'Success', 'actionid' => $actionId]), $events);
    }

    /**
     * Fails an action with an exception.
     */
    private function fail(string $actionId, Throwable $exception): void
    {
        $pending = $this->pending[$actionId];

        $this->cleanup($actionId);

        $pending->reject($exception);
    }

    /**
     * Removes all state related to an ActionID.
     */
    private function cleanup(string $actionId): void
    {
        unset($this->pending[$actionId]);
        unset($this->responses[$actionId]);
        unset($this->collectedEvents[$actionId]);
    }

    /**
     * Returns the number of pending actions.
     */
    public function count(): int
    {
        return count($this->pending);
    }
}
