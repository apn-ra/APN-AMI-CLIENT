<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

use Apn\AmiClient\Cluster\Contracts\RoutingStrategyInterface;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Core\EventFilter;
use Apn\AmiClient\Core\EventQueue;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Exceptions\AmiException;
use Apn\AmiClient\Transport\Reactor;
use Apn\AmiClient\Transport\TcpTransport;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates multiple AmiClient instances.
 */
class AmiClientManager
{
    /** @var array<string, AmiClientInterface> */
    private array $clients = [];

    private ?RoutingStrategyInterface $routingStrategy = null;

    private readonly Reactor $reactor;

    private string $defaultServer = 'default';

    private readonly LoggerInterface $logger;

    /** @var array<string, callable(AmiEvent): void[]> */
    private array $eventListeners = [];

    /** @var callable(AmiEvent): void[] */
    private array $anyEventListeners = [];

    public function __construct(
        private readonly ServerRegistry $registry = new ServerRegistry(),
        private readonly ClientOptions $options = new ClientOptions(),
        ?LoggerInterface $logger = null,
        ?Reactor $reactor = null
    ) {
        $this->reactor = $reactor ?? new Reactor();
        $this->logger = $logger ?? new Logger();

        if (!$this->options->lazy) {
            $this->connectAll();
        }
    }

    /**
     * Register a new client with the manager.
     */
    public function addClient(string $key, AmiClientInterface $client): void
    {
        $this->clients[$key] = $client;

        // Register event forwarding
        $client->onAnyEvent(function (AmiEvent $event) {
            $this->dispatchGlobalEvent($event);
        });

        // If it's an AmiClient using TcpTransport, register with reactor
        if ($client instanceof AmiClient) {
            $transport = $client->getTransport();

            if ($transport instanceof TcpTransport) {
                $this->reactor->register($key, $transport);
            }
        }
    }

    /**
     * Returns a client for the specified server key.
     */
    public function server(string $key): AmiClientInterface
    {
        if (!isset($this->clients[$key])) {
            try {
                $config = $this->registry->get($key);
                $this->addClient($key, $this->createClient($config));
            } catch (\InvalidArgumentException $e) {
                // If it's not in the registry and not manually added, it's missing.
                throw new \InvalidArgumentException(sprintf("AMI server '%s' not configured", $key), 0, $e);
            }
        }

        return $this->clients[$key];
    }

    /**
     * Returns the default client.
     */
    public function default(): AmiClientInterface
    {
        return $this->server($this->defaultServer);
    }

    /**
     * Sets the default server key.
     */
    public function setDefaultServer(string $key): void
    {
        if (!isset($this->clients[$key])) {
            try {
                $this->registry->get($key);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException(sprintf("AMI server '%s' not configured", $key));
            }
        }
        $this->defaultServer = $key;
    }

    /**
     * Sets the routing strategy.
     */
    public function routing(RoutingStrategyInterface $strategy): self
    {
        $this->routingStrategy = $strategy;
        return $this;
    }

    /**
     * Selects a client based on the current routing strategy.
     */
    public function select(): AmiClientInterface
    {
        if (empty($this->clients)) {
            throw new \Apn\AmiClient\Exceptions\AmiException("No AMI servers configured");
        }

        if ($this->routingStrategy === null) {
            return $this->default();
        }

        return $this->routingStrategy->select($this->clients);
    }

    /**
     * Executes tick() for a specific server.
     */
    public function tick(string $serverKey, int $timeoutMs = 0): void
    {
        $this->server($serverKey)->tick($timeoutMs);
    }

    /**
     * Iterates through all active server connections and executes their tick() logic.
     * Guideline 2: Stream Select Ownership.
     */
    public function tickAll(int $timeoutMs = 0): void
    {
        // 1. I/O Multiplexing via Reactor (Guideline 2)
        try {
            $this->reactor->tick($timeoutMs);
        } catch (\Throwable $e) {
            // Guideline 5: Node Isolation. A failure in one node (or the reactor) 
            // should not impact others if possible. But reactor failure is critical.
            $this->logger->error('Reactor tick failure', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // 2. Internal state processing for all clients
        foreach ($this->clients as $key => $client) {
            try {
                $client->processTick();
            } catch (\Throwable $e) {
                // Guideline 5: Node Isolation. Failure in one client must not block others.
                $this->logger->error('Client processTick failure', [
                    'server_key' => $key,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Register a listener for ANY event from ANY server.
     *
     * @param callable(AmiEvent): void $listener
     */
    public function onAnyEvent(callable $listener): void
    {
        $this->anyEventListeners[] = $listener;
    }

    /**
     * Register a listener for a specific event from ANY server.
     *
     * @param string $eventName
     * @param callable(AmiEvent): void $listener
     */
    public function onEvent(string $eventName, callable $listener): void
    {
        $eventName = strtolower($eventName);
        $this->eventListeners[$eventName][] = $listener;
    }

    /**
     * Dispatches an event to all registered global listeners.
     */
    private function dispatchGlobalEvent(AmiEvent $event): void
    {
        // 1. Specific event listeners
        $eventName = strtolower($event->getName());
        if (isset($this->eventListeners[$eventName])) {
            foreach ($this->eventListeners[$eventName] as $listener) {
                $listener($event);
            }
        }

        // 2. Catch-all listeners
        foreach ($this->anyEventListeners as $listener) {
            $listener($event);
        }
    }

    /**
     * Returns a summary of all managed clients.
     *
     * @return array<string, array{
     *     server_key: string,
     *     status: string,
     *     connected: bool,
     *     memory_usage_bytes: int,
     *     pending_actions: int,
     *     dropped_events: int
     * }>
     */
    public function health(): array
    {
        $health = [];

        // 1. All servers from registry
        foreach ($this->registry->all() as $key => $config) {
            if (isset($this->clients[$key])) {
                $health[$key] = $this->clients[$key]->health();
            } else {
                $health[$key] = [
                    'server_key' => $key,
                    'status' => 'disconnected',
                    'connected' => false,
                    'memory_usage_bytes' => 0,
                    'pending_actions' => 0,
                    'dropped_events' => 0,
                ];
            }
        }

        // 2. Manually added clients not in registry
        foreach ($this->clients as $key => $client) {
            if (!isset($health[$key])) {
                $health[$key] = $client->health();
            }
        }

        return $health;
    }

    /**
     * Gracefully closes all connections.
     */
    public function terminate(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
    }

    /**
     * Connects all servers in the registry (Eager-open).
     */
    public function connectAll(): void
    {
        foreach ($this->registry->all() as $key => $config) {
            $this->server($key)->open();
        }
    }

    /**
     * Creates an AmiClient instance from ServerConfig.
     */
    private function createClient(ServerConfig $config): AmiClientInterface
    {
        $options = $config->options ?? $this->options;

        $transport = new TcpTransport(
            $config->host,
            $config->port,
            $options->connectTimeout,
            $options->writeBufferLimit
        );

        $correlation = new CorrelationRegistry($options->maxPendingActions);
        $actionIdGenerator = new ActionIdGenerator($config->key);
        $eventQueue = new EventQueue($options->eventQueueCapacity);
        $eventFilter = new EventFilter($options->allowedEvents, $options->blockedEvents);

        $client = new AmiClient(
            serverKey: $config->key,
            transport: $transport,
            correlation: $correlation,
            actionIdGenerator: $actionIdGenerator,
            eventQueue: $eventQueue,
            eventFilter: $eventFilter,
            logger: $this->logger instanceof Logger ? $this->logger->withServerKey($config->key) : $this->logger,
            host: $config->host,
            port: $config->port
        );

        if ($options->memoryLimit > 0) {
            $client->setMemoryLimit($options->memoryLimit);
        }

        if ($config->username !== null && $config->secret !== null) {
            $client->setCredentials($config->username, $config->secret);
        }

        return $client;
    }

    /**
     * Registers SIGTERM and SIGINT signal handlers if pcntl is available.
     * Guideline 3: Graceful Shutdown.
     */
    public function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $handler = function (int $signal) {
                $this->terminate();
                exit(0);
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }
    }
}
