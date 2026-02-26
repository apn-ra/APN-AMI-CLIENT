<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Core\Contracts\EventFilterInterface;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\PendingAction;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Exceptions\InvalidConnectionStateException;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Banner;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Login;
use Apn\AmiClient\Protocol\Logoff;
use Apn\AmiClient\Protocol\Message;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Ping;
use Apn\AmiClient\Protocol\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * High-level AMI Client that integrates Transport, Parser, and Correlation.
 */
class AmiClient implements AmiClientInterface
{
    private readonly Parser $parser;
    private readonly ConnectionManager $connectionManager;
    private readonly MetricsCollectorInterface $metrics;

    /** @var array<string, array<int, callable>> Map of event name to listeners */
    private array $eventListeners = [];

    /** @var array<int, callable> List of listeners for all events */
    private array $anyEventListeners = [];

    private readonly EventQueue $eventQueue;
    private readonly EventFilterInterface $eventFilter;
    private readonly LoggerInterface $logger;

    private int $memoryLimit = 0;

    private ?string $username = null;
    private ?string $secret = null;

    private string $host = 'unknown';
    private int $port = 0;

    public function __construct(
        private readonly string $serverKey,
        private readonly TransportInterface $transport,
        private readonly CorrelationManager $correlation,
        ?Parser $parser = null,
        ?ConnectionManager $connectionManager = null,
        ?EventQueue $eventQueue = null,
        ?EventFilterInterface $eventFilter = null,
        ?LoggerInterface $logger = null,
        ?MetricsCollectorInterface $metrics = null,
        string $host = 'unknown',
        int $port = 0,
        private int $maxFramesPerTick = 1000,
        private int $maxEventsPerTick = 1000,
        private int $maxConnectAttemptsPerTick = 5,
        private float $readTimeout = 30.0,
        private int $circuitFailureThreshold = 5,
        private float $circuitCooldown = 30.0,
        private int $circuitHalfOpenMaxProbes = 1,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->metrics = $metrics ?? new NullMetricsCollector();
        
        if ($logger === null) {
            $this->logger = (new Logger())->withServerKey($serverKey);
        } else {
            $this->logger = $logger;
        }

        $labels = [
            'server_key' => $serverKey,
            'server_host' => $host,
        ];

        $this->parser = $parser ?? new Parser();
        $this->connectionManager = $connectionManager ?? new ConnectionManager(
            maxConnectAttemptsPerTick: $this->maxConnectAttemptsPerTick,
            readTimeout: $this->readTimeout,
            circuitFailureThreshold: $this->circuitFailureThreshold,
            circuitCooldown: $this->circuitCooldown,
            circuitHalfOpenMaxProbes: $this->circuitHalfOpenMaxProbes,
            metrics: $this->metrics, 
            labels: $labels,
            logger: $this->logger
        );
        $this->eventQueue = $eventQueue ?? new EventQueue(metrics: $this->metrics, labels: $labels);
        $this->eventFilter = $eventFilter ?? new EventFilter();
        
        $this->transport->onData($this->onRawData(...));
    }

    /**
     * Set credentials for automatic login.
     */
    public function setCredentials(string $username, #[\SensitiveParameter] string $secret): void
    {
        $this->username = $username;
        $this->secret = $secret;
    }

    /**
     * @inheritDoc
     */
    public function open(): void
    {
        if ($this->connectionManager->getStatus() === HealthStatus::DISCONNECTED) {
            $this->connectionManager->setStatus(HealthStatus::CONNECTING);
        }
        $this->transport->open();
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->transport->isConnected()) {
            try {
                $this->sendInternal(new Logoff());
                // Give it a tiny moment to flush the logoff if possible
                $this->transport->tick(10);
            } catch (Throwable) {
                // Ignore errors during logoff
            }
        }

        $this->transport->close();
        $this->connectionManager->setStatus(HealthStatus::DISCONNECTED);
        $this->correlation->failAll('Client closed');
    }

    /**
     * @inheritDoc
     */
    public function send(Action $action): PendingAction
    {
        if (!$this->connectionManager->getStatus()->isAvailable()) {
            throw new InvalidConnectionStateException(
                $this->serverKey,
                $this->connectionManager->getStatus()->value
            );
        }

        return $this->sendInternal($action);
    }

    /**
     * Internal send used for login/heartbeat/logoff during non-READY states.
     */
    private function sendInternal(Action $action): PendingAction
    {
        // Guideline 4: All ActionIDs must follow the format: {server_key}:{instance_id}:{sequence_id}.
        // We use CorrelationManager/ActionIdGenerator to ensure this.
        $actionId = $this->correlation->nextActionId();
        $action = $action->withActionId($actionId);

        // Register in correlation registry before sending
        try {
            $pending = $this->correlation->register($action);
        } catch (BackpressureException $e) {
            $this->logger->warning('Action rejected due to backpressure', [
                'action_id' => $actionId,
                'action_name' => $action->getActionName(),
                'pending_count' => $this->correlation->count(),
                'queue_depth' => $this->correlation->count(),
                'queue_type' => 'pending_actions',
            ]);

            $this->metrics->increment('ami_write_buffer_backpressure_events_total', [
                'server_key' => $this->serverKey,
                'server_host' => $this->host,
                'reason' => 'registry_full',
            ]);

            throw $e;
        }

        // Record latency on completion
        $pending->onComplete(function () use ($action, $pending) {
            $duration = (microtime(true) - $pending->getCreatedAt()) * 1000;
            $this->metrics->record('ami_action_latency_ms', $duration, [
                'server_key' => $this->serverKey,
                'server_host' => $this->host,
                'action' => $action->getActionName(),
            ]);
        });

        // Serialize and send
        $raw = $this->serializeAction($action);
        try {
            $this->transport->send($raw);
        } catch (BackpressureException $e) {
            $this->logger->warning('Action rejected due to transport backpressure', [
                'action_id' => $actionId,
                'action_name' => $action->getActionName(),
                'queue_depth' => $this->transport->getPendingWriteBytes(),
                'queue_type' => 'write_buffer_bytes',
            ]);

            $this->metrics->increment('ami_write_buffer_backpressure_events_total', [
                'server_key' => $this->serverKey,
                'server_host' => $this->host,
                'reason' => 'transport_buffer_full',
            ]);

            throw $e;
        }

        return $pending;
    }

    /**
     * Set the memory limit in bytes for OOM protection.
     */
    public function setMemoryLimit(int $limit): void
    {
        $this->memoryLimit = $limit;
    }

    /**
     * @inheritDoc
     */
    public function onEvent(string $name, callable $listener): void
    {
        $this->eventListeners[strtolower($name)][] = $listener;
    }

    /**
     * @inheritDoc
     */
    public function onAnyEvent(callable $listener): void
    {
        $this->anyEventListeners[] = $listener;
    }

    /**
     * @inheritDoc
     */
    public function tick(int $timeoutMs = 0): void
    {
        $this->resetTickBudgets();

        // 1. I/O Multiplexing
        $this->transport->tick($timeoutMs);

        // 2. Internal processing (timeouts, health)
        $this->processTick(true);
    }

    /**
     * Resets tick-based budgets.
     */
    public function resetTickBudgets(): void
    {
        $this->connectionManager->resetTickBudgets();
    }

    /**
     * Performs internal processing (timeouts, health) without I/O multiplexing.
     * This is used when an external reactor handles the stream_select call.
     */
    public function processTick(bool $canAttemptConnect = true): bool
    {
        $attemptedConnect = false;

        // 0. OOM Protection (Guideline 8, Task 6.6)
        if ($this->memoryLimit > 0 && memory_get_usage() > $this->memoryLimit) {
            $this->terminate();
            return false;
        }

        // 1. Protocol Parsing with budget (Task 3.2)
        $framesProcessed = 0;
        try {
            while ($framesProcessed < $this->maxFramesPerTick && ($message = $this->parser->next())) {
                $this->dispatchMessage($message);
                $framesProcessed++;
            }
        } catch (Throwable $e) {
            $this->logger->error('Protocol error or parser desync during processing', [
                'server_key' => $this->serverKey,
                'exception' => $e->getMessage(),
            ]);
            $this->close();
            return false;
        }

        // 2. Correlation Timeout Sweeps (Guideline 2 & 4)
        $this->correlation->sweep();

        // 3. State management & Authentication State Machine (Phase 4)
        if (!$this->transport->isConnected()) {
            $currentStatus = $this->connectionManager->getStatus();
            if ($currentStatus !== HealthStatus::DISCONNECTED 
                && $currentStatus !== HealthStatus::CONNECTING
                && $currentStatus !== HealthStatus::RECONNECTING) {
                $this->connectionManager->setStatus(HealthStatus::DISCONNECTED);
                $this->parser->reset();
                $this->correlation->failAll('Connection lost');
            }

            if ($currentStatus === HealthStatus::CONNECTING && $this->connectionManager->isConnectTimedOut()) {
                $delay = $this->connectionManager->previewReconnectDelay();
                $this->logger->warning('Connection timed out, scheduling reconnect...', [
                    'server_key' => $this->serverKey,
                    'host' => $this->host,
                    'port' => $this->port,
                    'attempt' => $this->connectionManager->getReconnectAttempts() + 1,
                    'backoff' => $delay,
                    'next_retry_at' => $this->connectionManager->previewReconnectAt($delay),
                    'queue_depth' => $this->eventQueue->count(),
                    'queue_type' => 'event_queue',
                ]);
                $this->transport->close();
                $this->connectionManager->recordConnectTimeout();
                return false;
            }
            
            if ($canAttemptConnect && $this->connectionManager->shouldAttemptReconnect()) {
                $delay = $this->connectionManager->previewReconnectDelay();
                $this->logger->warning('Reconnecting...', [
                    'server_key' => $this->serverKey,
                    'host' => $this->host,
                    'port' => $this->port,
                    'attempt' => $this->connectionManager->getReconnectAttempts() + 1,
                    'backoff' => $delay,
                    'next_retry_at' => $this->connectionManager->previewReconnectAt($delay),
                    'queue_depth' => $this->eventQueue->count(),
                    'queue_type' => 'event_queue',
                ]);
                $this->connectionManager->recordReconnectAttempt();
                $attemptedConnect = true;
                try {
                    $this->open();
                } catch (Throwable $e) {
                     $delay = $this->connectionManager->previewReconnectDelay();
                     $this->logger->warning('Connection failed, scheduling reconnect...', [
                         'server_key' => $this->serverKey,
                         'host' => $this->host,
                         'port' => $this->port,
                         'attempt' => $this->connectionManager->getReconnectAttempts() + 1,
                         'backoff' => $delay,
                         'next_retry_at' => $this->connectionManager->previewReconnectAt($delay),
                         'queue_depth' => $this->eventQueue->count(),
                         'queue_type' => 'event_queue',
                     ]);
                     $this->connectionManager->recordConnectFailure();
                     $this->connectionManager->setStatus(HealthStatus::DISCONNECTED);
                }
            }
         } else {
             $currentStatus = $this->connectionManager->getStatus();

             if ($this->connectionManager->isReadTimedOut()) {
                 $delay = $this->connectionManager->previewReconnectDelay();
                 $this->logger->warning('Read timed out, scheduling reconnect...', [
                     'server_key' => $this->serverKey,
                     'host' => $this->host,
                     'port' => $this->port,
                     'attempt' => $this->connectionManager->getReconnectAttempts() + 1,
                     'backoff' => $delay,
                     'next_retry_at' => $this->connectionManager->previewReconnectAt($delay),
                     'queue_depth' => $this->eventQueue->count(),
                     'queue_type' => 'event_queue',
                 ]);
                 $this->close();
                 $this->connectionManager->recordReadTimeout();
                 return false;
             }

             // Check for login timeout (Phase 4 Task 4.2)
             if ($this->connectionManager->isLoginTimedOut()) {
                 $delay = $this->connectionManager->previewReconnectDelay();
                 $this->logger->warning('Authentication timed out, reconnecting...', [
                    'server_key' => $this->serverKey,
                    'host' => $this->host,
                    'port' => $this->port,
                    'attempt' => $this->connectionManager->getReconnectAttempts() + 1,
                    'backoff' => $delay,
                    'next_retry_at' => $this->connectionManager->previewReconnectAt($delay),
                    'queue_depth' => $this->eventQueue->count(),
                    'queue_type' => 'event_queue',
                 ]);
                 $this->close();
                 return false;
             }
             
             if ($currentStatus === HealthStatus::DISCONNECTED) {
                 // If we just failed login, close once to force reconnect with backoff.
                 if ($this->connectionManager->consumeLoginFailureSignal()) {
                     $this->close();
                     return $attemptedConnect;
                 }
                 $this->connectionManager->setStatus(HealthStatus::CONNECTED);
                 $currentStatus = HealthStatus::CONNECTED;
             }

             // Handle transitions from CONNECTING -> CONNECTED -> AUTHENTICATING -> READY
             $currentStatus = $this->connectionManager->getStatus();

             if ($currentStatus === HealthStatus::CONNECTING) {
                 $this->connectionManager->setStatus(HealthStatus::CONNECTED);
                 $currentStatus = HealthStatus::CONNECTED;
             }

             if ($currentStatus === HealthStatus::CONNECTED) {
                 if ($this->username !== null && $this->secret !== null) {
                     if (!$this->connectionManager->hasLoginStarted()) {
                         $this->login();
                     }
                 } else {
                     $this->connectionManager->setStatus(HealthStatus::READY);
                 }
             } elseif ($currentStatus === HealthStatus::AUTHENTICATING && !$this->connectionManager->hasLoginStarted()) {
                 if ($this->username !== null && $this->secret !== null) {
                     $this->login();
                 } else {
                     $this->connectionManager->setStatus(HealthStatus::READY);
                 }
             }

             // Handle heartbeats (Guideline 7)
             if ($this->connectionManager->shouldSendHeartbeat()) {
                 $this->ping();
             }
         }

        // 4. Process Event Queue with budget (Task 3.3)
        $eventsProcessed = 0;
        while ($eventsProcessed < $this->maxEventsPerTick && ($event = $this->eventQueue->pop())) {
             $name = strtolower($event->getName());

             if (isset($this->eventListeners[$name])) {
                 foreach ($this->eventListeners[$name] as $listener) {
                     try {
                         $listener($event);
                     } catch (Throwable $e) {
                         $this->logger->error('Event listener failed', [
                             'server_key' => $this->serverKey,
                             'event_name' => $event->getName(),
                             'exception' => $e->getMessage(),
                         ]);
                     }
                 }
             }

             foreach ($this->anyEventListeners as $listener) {
                 try {
                     $listener($event);
                 } catch (Throwable $e) {
                     $this->logger->error('Any-event listener failed', [
                         'server_key' => $this->serverKey,
                         'event_name' => $event->getName(),
                         'exception' => $e->getMessage(),
                     ]);
                 }
             }
             $eventsProcessed++;
         }

         return $attemptedConnect;
     }

     /**
      * Emergency shutdown and resource release.
      */
     public function terminate(): void
     {
         $this->close();
         $this->transport->terminate();
         $this->correlation->failAll('Client terminated');
     }

    /**
     * Initiate login sequence.
     */
    private function login(): void
    {
        if ($this->username === null || $this->secret === null) {
            return;
        }

        $this->connectionManager->recordLoginAttempt();
        
        $action = new Login($this->username, $this->secret);
        $this->sendInternal($action)->onComplete(function (?Throwable $e, ?Response $r) {
            if ($e === null && $r !== null && $r->isSuccess()) {
                $this->connectionManager->recordLoginSuccess();
            } else {
                // Record failure; reconnection/close will be handled by processTick()
                $this->connectionManager->recordLoginFailure();
            }
        });
    }

    /**
     * Send heartbeat ping.
     */
    private function ping(): void
    {
        $this->connectionManager->recordHeartbeatSent();
        $action = new Ping();
        $this->sendInternal($action)->onComplete(function (?Throwable $e, ?Response $r) {
            if ($e === null && $r !== null && $r->isSuccess()) {
                $this->connectionManager->recordHeartbeatSuccess();
            } else {
                $this->connectionManager->recordHeartbeatFailure();
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        return $this->transport->isConnected();
    }

    /**
     * @inheritDoc
     */
    public function getServerKey(): string
    {
        return $this->serverKey;
    }

    /**
     * @inheritDoc
     */
    public function getHealthStatus(): HealthStatus
    {
        return $this->connectionManager->getStatus();
    }

    /**
     * @inheritDoc
     */
    public function health(): array
    {
        return [
            'server_key' => $this->serverKey,
            'status' => $this->connectionManager->getStatus()->value,
            'connected' => $this->transport->isConnected(),
            'memory_usage_bytes' => memory_get_usage(),
            'pending_actions' => $this->correlation->count(),
            'dropped_events' => $this->eventQueue->getDroppedEventsCount(),
        ];
    }

    /**
     * Internal access to connection manager.
     * @internal
     */
    public function getConnectionManager(): ConnectionManager
    {
        return $this->connectionManager;
    }

    /**
     * Internal access to transport for reactor registration.
     * @internal
     */
    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Handle raw data from transport.
     */
    private function onRawData(string $data): void
    {
        try {
            $this->parser->push($data);
            $this->connectionManager->recordRead();
        } catch (Throwable $e) {
            $this->logger->error('Parser push error', [
                'server_key' => $this->serverKey,
                'exception' => $e->getMessage(),
            ]);
            $this->close();
        }
    }

    /**
     * Dispatch parsed message to the appropriate handler.
     */
    private function dispatchMessage(Message $message): void
    {
        if ($message instanceof Banner) {
            $this->logger->info("AMI connection banner received: {$message->getVersionString()}", [
                'server_key' => $this->serverKey,
                'banner' => $message->getVersionString(),
            ]);
            $this->connectionManager->recordBannerReceived();
            return;
        }

        if ($message instanceof Response) {
            $this->correlation->handleResponse($message);
            return;
        }

        if ($message instanceof Event) {
            // Guideline 4 / Phase 4 Task 5: Prevent event dispatch before AUTHENTICATING completes.
            // Some events might come during authentication, but we should only process them if fully healthy.
            // Exception: Events that are part of a pending action (though few actions return events during login).
            
            // Check if it's an event belonging to a pending action
            $this->correlation->handleEvent($message);

            if ($this->username !== null && $this->secret !== null && !$this->connectionManager->getStatus()->isAvailable()) {
                return;
            }

            $amiEvent = AmiEvent::create($message, $this->serverKey);

            // Filter and queue event (Task 6.1, 6.3)
            if ($this->eventFilter->shouldKeep($amiEvent)) {
                $beforeDroppedCount = $this->eventQueue->getDroppedEventsCount();
                
                $this->eventQueue->push($amiEvent);

                if ($this->eventQueue->getDroppedEventsCount() > $beforeDroppedCount) {
                    $this->logger->warning('Event dropped due to queue capacity', [
                        'server_key' => $this->serverKey,
                        'event_name' => $amiEvent->getName(),
                        'queue_depth' => $this->eventQueue->count(),
                        'queue_type' => 'event_queue',
                        'dropped_total' => $this->eventQueue->getDroppedEventsCount(),
                    ]);
                }
            }
        }
    }

    /**
     * Serialize Action to raw AMI protocol string.
     */
    private function serializeAction(Action $action): string
    {
        $lines = [];
        $lines[] = 'Action: ' . $action->getActionName();
        $lines[] = 'ActionID: ' . $action->getActionId();

        foreach ($action->getParameters() as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $lines[] = sprintf('%s: %s', $key, $v);
                }
            } else {
                $lines[] = sprintf('%s: %s', $key, $value);
            }
        }

        return implode("\r\n", $lines) . "\r\n\r\n";
    }
}
