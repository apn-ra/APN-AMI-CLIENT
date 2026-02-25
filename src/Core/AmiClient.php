<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Core\Contracts\EventFilterInterface;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Correlation\PendingAction;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\Action;
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
        private readonly CorrelationRegistry $correlation,
        private readonly ActionIdGenerator $actionIdGenerator,
        ?Parser $parser = null,
        ?ConnectionManager $connectionManager = null,
        ?EventQueue $eventQueue = null,
        ?EventFilterInterface $eventFilter = null,
        ?LoggerInterface $logger = null,
        ?MetricsCollectorInterface $metrics = null,
        string $host = 'unknown',
        int $port = 0
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
        $this->connectionManager = $connectionManager ?? new ConnectionManager(metrics: $this->metrics, labels: $labels);
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
                $this->send(new Logoff());
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
        // Guideline 4: All ActionIDs must follow the format: {server_key}:{instance_id}:{sequence_id}.
        // We use ActionIdGenerator to ensure this.
        $actionId = $this->actionIdGenerator->next();
        $action = $action->withActionId($actionId);

        // Register in correlation registry before sending
        try {
            $pending = $this->correlation->register($action);
        } catch (BackpressureException $e) {
            $this->logger->warning('Action rejected due to backpressure', [
                'action_id' => $actionId,
                'action_name' => $action->getActionName(),
                'pending_count' => $this->correlation->count(),
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
        // 1. I/O Multiplexing
        $this->transport->tick($timeoutMs);

        // 2. Internal processing (timeouts, health)
        $this->processTick();
    }

    /**
     * Performs internal processing (timeouts, health) without I/O multiplexing.
     * This is used when an external reactor handles the stream_select call.
     */
    public function processTick(): void
    {
        // 0. OOM Protection (Guideline 8, Task 6.6)
        if ($this->memoryLimit > 0 && memory_get_usage() > $this->memoryLimit) {
            // Trigger emergency shutdown
            $this->terminate();
            return;
        }

        // 1. Correlation Timeout Sweeps (Guideline 2 & 4)
        $this->correlation->sweep();

        // 2. State management & Reconnection (Phase 5)
        if (!$this->transport->isConnected()) {
            $currentStatus = $this->connectionManager->getStatus();
            if ($currentStatus !== HealthStatus::DISCONNECTED 
                && $currentStatus !== HealthStatus::CONNECTING
                && $currentStatus !== HealthStatus::RECONNECTING) {
                $this->connectionManager->setStatus(HealthStatus::DISCONNECTED);
                $this->correlation->failAll('Connection lost');
            }
            
            if ($this->connectionManager->shouldAttemptReconnect()) {
                $this->connectionManager->recordReconnectAttempt();
                try {
                    $this->open();
                } catch (Throwable $e) {
                     // Reconnect attempt failed, back to DISCONNECTED
                     $this->connectionManager->setStatus(HealthStatus::DISCONNECTED);
                }
            }
        } else {
             // We are connected at transport level
             $currentStatus = $this->connectionManager->getStatus();
             
             if ($currentStatus === HealthStatus::CONNECTING || 
                 $currentStatus === HealthStatus::RECONNECTING ||
                 $currentStatus === HealthStatus::DISCONNECTED) {
                 
                 if ($this->username !== null && $this->secret !== null) {
                     $this->login();
                 } else {
                     $this->connectionManager->setStatus(HealthStatus::CONNECTED_HEALTHY);
                 }
             }

             // Handle heartbeats (Guideline 7)
             if ($this->connectionManager->shouldSendHeartbeat()) {
                 $this->ping();
             }
         }

         // 3. Process Event Queue (Task 6.2)
         while ($event = $this->eventQueue->pop()) {
             $name = strtolower($event->getName());

             if (isset($this->eventListeners[$name])) {
                 foreach ($this->eventListeners[$name] as $listener) {
                     $listener($event);
                 }
             }

             foreach ($this->anyEventListeners as $listener) {
                 $listener($event);
             }
         }
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

        $this->connectionManager->setStatus(HealthStatus::AUTHENTICATING);
        
        $action = new Login($this->username, $this->secret);
        $this->send($action)->onComplete(function (?Throwable $e, ?Response $r) {
            if ($e === null && $r !== null && $r->isSuccess()) {
                $this->connectionManager->recordLoginSuccess();
            } else {
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
        $this->send($action)->onComplete(function (?Throwable $e, ?Response $r) {
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
        $this->parser->push($data);

        while ($message = $this->parser->next()) {
            $this->dispatchMessage($message);
        }
    }

    /**
     * Dispatch parsed message to the appropriate handler.
     */
    private function dispatchMessage(Message $message): void
    {
        if ($message instanceof Response) {
            $this->correlation->handleResponse($message);
            return;
        }

        if ($message instanceof Event) {
            // Check if it's an event belonging to a pending action
            $this->correlation->handleEvent($message);

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
