<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

use Apn\AmiClient\Exceptions\AmiTimeoutException;
use Apn\AmiClient\Exceptions\ConnectionLostException;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Exceptions\ActionErrorResponseException;
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
    private int $matchedResponses = 0;
    private int $unmatchedResponses = 0;
    private int $timeouts = 0;
    private int $completedActions = 0;
    private int $failedActions = 0;

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
            $this->callbackExceptionHandler,
            $this->logger
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
            $this->recordUnmatchedResponse($response, $actionId);
            return;
        }
        if (!$this->matchesExpectedServerKey($actionId)) {
            $this->recordServerSegmentMismatch('response', $actionId);
            return;
        }

        $pending = $this->pending[$actionId];
        $strategy = $pending->getAction()->getCompletionStrategy();
        $responseType = $this->responseType($response);
        $this->logDecision('matched', $actionId, $strategy::class, $responseType);
        $this->matchedResponses++;

        if ($strategy->onResponse($response)) {
            if ($responseType === 'error') {
                $this->fail(
                    $actionId,
                    new ActionErrorResponseException(
                        $actionId,
                        $response->getMessageHeader(),
                        'Error'
                    )
                );
                return;
            }

            $this->complete($actionId, $response, $strategy::class, $responseType);
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
        if (!$this->matchesExpectedServerKey($actionId)) {
            $this->recordServerSegmentMismatch('event', $actionId);
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
            $this->safeLogWarning('Correlation event dropped due to safety cap', [
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

            $this->complete($actionId, $response, $strategy::class, 'success');
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
                $this->timeouts++;
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
    private function complete(string $actionId, Response $response, string $strategyClass = 'unknown', string $responseType = 'unknown'): void
    {
        $pending = $this->pending[$actionId];
        $events = $this->collectedEvents[$actionId] ?? [];
        
        $this->cleanup($actionId);
        $this->completedActions++;
        $this->logDecision('completed', $actionId, $strategyClass, $responseType);

        $pending->resolve($response, $events);
    }

    /**
     * Fails an action with an exception.
     */
    private function fail(string $actionId, Throwable $exception): void
    {
        $pending = $this->pending[$actionId];
        $responseType = $exception instanceof ActionErrorResponseException
            ? strtolower((string) ($exception->getResponseType() ?? 'error'))
            : 'n/a';

        $this->cleanup($actionId);
        $this->failedActions++;
        $this->logDecision('failed', $actionId, $pending->getAction()->getCompletionStrategy()::class, $responseType);

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

    /**
     * @param array<string, mixed> $context
     */
    private function safeLogWarning(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // Logging must never interrupt runtime paths.
        }
    }

    /**
     * @param string|null $actionId
     */
    private function recordUnmatchedResponse(Response $response, ?string $actionId): void
    {
        $responseType = $this->responseType($response);
        $context = [
            'server_key' => $this->labels['server_key'] ?? 'unknown',
            'action_id' => $actionId,
            'response_type' => $responseType,
            'reason' => $actionId === null ? 'missing_action_id' : 'unknown_action_id',
        ];

        $this->safeLogWarning('Correlation response unmatched', $context);
        $this->unmatchedResponses++;
        $this->metrics->increment('ami_correlation_unmatched_responses_total', $this->labels);
        $this->logDecision('unmatched', $actionId, 'unknown', $responseType);
    }

    private function responseType(Response $response): string
    {
        $type = $response->getHeader('Response');
        return is_string($type) ? strtolower($type) : 'unknown';
    }

    private function matchesExpectedServerKey(string $actionId): bool
    {
        $expectedServerKey = $this->labels['server_key'] ?? '';
        if (!is_string($expectedServerKey) || $expectedServerKey === '') {
            return true;
        }

        $serverSegment = $this->extractServerSegment($actionId);
        if ($serverSegment === null) {
            return true;
        }

        return $serverSegment === $expectedServerKey;
    }

    private function extractServerSegment(string $actionId): ?string
    {
        if ($actionId === '') {
            return null;
        }

        $separatorPos = strpos($actionId, ':');
        if ($separatorPos === false || $separatorPos === 0) {
            return null;
        }

        return substr($actionId, 0, $separatorPos);
    }

    private function recordServerSegmentMismatch(string $messageType, string $actionId): void
    {
        $expectedServerKey = $this->labels['server_key'] ?? 'unknown';
        $observedServerKey = $this->extractServerSegment($actionId) ?? 'unknown';
        $context = [
            'server_key' => $expectedServerKey,
            'action_id' => $actionId,
            'reason' => 'server_segment_mismatch',
            'message_type' => $messageType,
            'expected_server_key' => $expectedServerKey,
            'observed_server_key' => $observedServerKey,
        ];

        $this->safeLogWarning('Correlation server segment mismatch', $context);
        $this->unmatchedResponses++;
        $this->metrics->increment('ami_correlation_server_segment_mismatch_total', $this->labels);
        $this->logDecision('unmatched', $actionId, 'unknown', 'unknown');
    }

    private function logDecision(string $decision, ?string $actionId, string $strategyClass, string $responseType): void
    {
        try {
            $this->logger->debug('Correlation response decision', [
                'server_key' => $this->labels['server_key'] ?? 'unknown',
                'action_id' => $actionId,
                'decision' => $decision,
                'strategy' => $strategyClass,
                'response_type' => $responseType,
            ]);
        } catch (\Throwable) {
            // Logging must never interrupt runtime paths.
        }
    }

    /**
     * @return array{
     *   pending:int,
     *   matched_responses:int,
     *   unmatched_responses:int,
     *   timeouts:int,
     *   completed_actions:int,
     *   failed_actions:int
     * }
     */
    public function diagnostics(): array
    {
        return [
            'pending' => count($this->pending),
            'matched_responses' => $this->matchedResponses,
            'unmatched_responses' => $this->unmatchedResponses,
            'timeouts' => $this->timeouts,
            'completed_actions' => $this->completedActions,
            'failed_actions' => $this->failedActions,
        ];
    }
}
