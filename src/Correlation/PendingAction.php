<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;
use Throwable;

/**
 * Represents an action that is currently awaiting a response from the AMI server.
 * Provides a mechanism to register callbacks for completion or failure.
 */
final class PendingAction
{
    private ?Response $response = null;
    /** @var Event[] */
    private array $events = [];
    private ?Throwable $exception = null;
    /** @var array<callable(Throwable|null, Response|null, Event[]): void> */
    private array $callbacks = [];
    /** @var callable(string, Throwable, Action): void|null */
    private $callbackExceptionHandler = null;

    private readonly float $createdAt;

    public function __construct(
        private readonly Action $action,
        private readonly float $timeoutAt,
        ?callable $callbackExceptionHandler = null
    ) {
        $this->createdAt = microtime(true);
        $this->callbackExceptionHandler = $callbackExceptionHandler;
    }

    /**
     * Get the creation time of this pending request (microtime).
     */
    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    /**
     * Get the action associated with this pending request.
     */
    public function getAction(): Action
    {
        return $this->action;
    }

    /**
     * Get the expiration time of this pending request (microtime).
     */
    public function getTimeoutAt(): float
    {
        return $this->timeoutAt;
    }

    /**
     * Check if this request has expired.
     */
    public function isExpired(float $now): bool
    {
        return $now >= $this->timeoutAt;
    }

    /**
     * Mark the action as resolved with the final response and collected events.
     *
     * @param Event[] $events
     */
    public function resolve(Response $response, array $events = []): void
    {
        $this->response = $response;
        $this->events = $events;
        $this->notify();
    }

    /**
     * Mark the action as failed with an exception.
     */
    public function reject(Throwable $exception): void
    {
        $this->exception = $exception;
        $this->notify();
    }

    /**
     * Register a callback to be executed when the action completes or fails.
     * The callback signature: function(?Throwable $e, ?Response $r, array $events): void
     *
     * @param callable(Throwable|null, Response|null, Event[]): void $callback
     */
    public function onComplete(callable $callback): self
    {
        if ($this->isFinished()) {
            $this->invokeCallback($callback);
        } else {
            $this->callbacks[] = $callback;
        }
        return $this;
    }

    /**
     * Returns true if the action is finished (either resolved or rejected).
     */
    public function isFinished(): bool
    {
        return $this->response !== null || $this->exception !== null;
    }

    /**
     * Trigger all registered callbacks and clear them.
     */
    private function notify(): void
    {
        foreach ($this->callbacks as $callback) {
            $this->invokeCallback($callback);
        }
        $this->callbacks = [];
    }

    /**
     * @param callable(Throwable|null, Response|null, Event[]): void $callback
     */
    private function invokeCallback(callable $callback): void
    {
        try {
            $callback($this->exception, $this->response, $this->events);
        } catch (Throwable $e) {
            $reportedByHandler = false;

            if ($this->callbackExceptionHandler !== null) {
                try {
                    ($this->callbackExceptionHandler)($this->callbackIdentity($callback), $e, $this->action);
                    $reportedByHandler = true;
                } catch (Throwable) {
                    // Never allow reporting failures to escape into tick/correlation flow.
                }
            }

            if (!$reportedByHandler) {
                $this->emitFallbackCallbackExceptionTelemetry($callback, $e);
            }
        }
    }

    private function emitFallbackCallbackExceptionTelemetry(callable $callback, Throwable $exception): void
    {
        $payload = [
            'type' => 'pending_action_callback_exception',
            'server_key' => $this->deriveServerKey(),
            'action_id' => $this->action->getActionId(),
            'action_name' => $this->action->getActionName(),
            'callback' => $this->callbackIdentity($callback),
            'callback_exception_class' => $exception::class,
            'callback_exception_message' => $exception->getMessage(),
        ];

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            error_log($encoded === false ? '{"type":"pending_action_callback_exception"}' : $encoded);
        } catch (Throwable) {
            error_log('{"type":"pending_action_callback_exception"}');
        }
    }

    private function deriveServerKey(): string
    {
        $actionId = $this->action->getActionId();
        if (!is_string($actionId) || $actionId === '') {
            return 'unknown';
        }

        $separatorPos = strpos($actionId, ':');
        if ($separatorPos === false || $separatorPos === 0) {
            return 'unknown';
        }

        return substr($actionId, 0, $separatorPos);
    }

    private function callbackIdentity(callable $callback): string
    {
        if ($callback instanceof \Closure) {
            return 'closure';
        }

        if (is_array($callback) && count($callback) === 2) {
            $target = is_object($callback[0]) ? $callback[0]::class : (string) $callback[0];
            return sprintf('%s::%s', $target, (string) $callback[1]);
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_object($callback)) {
            return $callback::class;
        }

        return 'callable';
    }
}
