<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

use Apn\AmiClient\Exceptions\AmiTimeoutException;
use Apn\AmiClient\Exceptions\ConnectionLostException;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Exceptions\MissingResponseException;
use Apn\AmiClient\Core\Contracts\EventOnlyCompletionStrategyInterface;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\NullMetricsCollector;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    /** @var callable(string, Throwable, Action): void|null */
    private $callbackExceptionHandler = null;

    private readonly LoggerInterface $logger;
    private readonly MetricsCollectorInterface $metrics;

    /**
     * @param int $maxPending Hard limit for concurrent pending actions.
     */
    public function __construct(
        private readonly int $maxPending = 5000,
        ?callable $callbackExceptionHandler = null,
        ?LoggerInterface $logger = null,
        ?MetricsCollectorInterface $metrics = null,
        private readonly array $labels = []
    ) {
        $this->callbackExceptionHandler = $callbackExceptionHandler;
        $this->logger = $logger ?? new NullLogger();
        $this->metrics = $metrics ?? new NullMetricsCollector();
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

        $pendingAction = new PendingAction(
            $action,
            microtime(true) + $timeoutSeconds,
            $this->callbackExceptionHandler
        );
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
        } else {
            // Safety cap reached (Phase 3, Task 4.1)
            $this->logger->warning('Correlation event dropped due to safety cap', [
                'server_key' => $this->labels['server_key'] ?? 'unknown',
                'action_id' => $actionId,
                'max_messages' => $maxMessages,
            ]);
            $this->metrics->increment('ami_correlation_events_dropped_total', $this->labels);
        }

        if ($strategy->onEvent($event)) {
            $response = $this->responses[$actionId] ?? null;
            if ($response === null) {
                if (!$strategy instanceof EventOnlyCompletionStrategyInterface) {
                    $this->fail(
                        $actionId,
                        new MissingResponseException(
                            sprintf('ActionID %s completed without an AMI response', $actionId)
                        )
                    );
                    return;
                }

                $response = new Response(['response' => 'Success', 'actionid' => $actionId]);
            }

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
     * Rejects and removes a pending action if it exists.
     *
     * @return bool True when an action existed and was removed.
     */
    public function rollback(string $actionId, Throwable $exception): bool
    {
        if (!isset($this->pending[$actionId])) {
            return false;
        }

        $this->fail($actionId, $exception);
        return true;
    }

    /**
     * Sets the callback exception handler for future pending actions.
     */
    public function setCallbackExceptionHandler(?callable $handler): void
    {
        $this->callbackExceptionHandler = $handler;
    }

    /**
     * Cleanly completes an action.
     */
    private function complete(string $actionId, Response $response): void
    {
        $pending = $this->pending[$actionId];
        $events = $this->collectedEvents[$actionId] ?? [];
        
        $this->cleanup($actionId);

        $pending->resolve($response, $events);
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
