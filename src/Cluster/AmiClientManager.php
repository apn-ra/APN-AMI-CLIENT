<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

use Apn\AmiClient\Cluster\Contracts\RoutingStrategyInterface;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\EventFilter;
use Apn\AmiClient\Core\EventQueue;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Core\NullMetricsCollector;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Exceptions\AmiException;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Protocol\Parser;
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

    private int $connectAttemptsThisTick = 0;
    private int $reconnectCursor = 0;
    private readonly MetricsCollectorInterface $metrics;
    /** @var array<string, string> */
    private array $resolvedHostsByKey = [];
    /** @var array<string, string> */
    private array $resolvedHostCache = [];
    /** @var null|callable(string): string */
    private $hostnameResolver;
    /** @var null|callable(int): void */
    private $signalHandler;

    public function __construct(
        private readonly ServerRegistry $registry = new ServerRegistry(),
        private readonly ClientOptions $options = new ClientOptions(),
        ?LoggerInterface $logger = null,
        ?Reactor $reactor = null,
        ?MetricsCollectorInterface $metrics = null,
        ?callable $hostnameResolver = null,
        ?callable $signalHandler = null
    ) {
        $this->metrics = $metrics ?? new NullMetricsCollector();
        $this->hostnameResolver = $hostnameResolver;
        $this->signalHandler = $signalHandler;
        $bootstrapLogger = $logger ?? new Logger();

        try {
            $redactor = $this->options->createRedactor();
        } catch (InvalidConfigurationException $e) {
            $bootstrapLogger->error('Invalid redaction pattern configuration', [
                'pattern_type' => $e->getPatternType() ?? 'unknown',
                'pattern' => $e->getPattern(),
                'pattern_error' => $e->getPatternError(),
            ]);

            $this->metrics->increment('ami_redaction_pattern_invalid_total', [
                'pattern_type' => $e->getPatternType() ?? 'unknown',
            ]);

            throw $e;
        }

        $this->logger = $logger ?? new Logger($redactor);
        $this->reactor = $reactor ?? new Reactor(
            $this->logger,
            $this->metrics
        );

        foreach ($this->registry->all() as $config) {
            $options = $config->options ?? $this->options;
            $this->validateHostnamePolicy($config, $options);
            $this->resolvedHostsByKey[$config->key] = $this->resolveHost($config, $options);
        }

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
        $effectiveTimeoutMs = $this->normalizeRuntimeTimeoutMs($timeoutMs);
        $this->server($serverKey)->tick($effectiveTimeoutMs);
    }

    /**
     * Alias for tick($serverKey, 0).
     */
    public function poll(string $serverKey): void
    {
        $this->tick($serverKey, 0);
    }

    /**
     * Iterates through all active server connections and executes their tick() logic.
     * Guideline 2: Stream Select Ownership.
     */
    public function tickAll(int $timeoutMs = 0): void
    {
        $effectiveTimeoutMs = $this->normalizeRuntimeTimeoutMs($timeoutMs);

        // 0. Reset budgets for all clients
        foreach ($this->clients as $client) {
            if ($client instanceof AmiClient) {
                $client->resetTickBudgets();
            }
        }

        // 1. I/O Multiplexing via Reactor (Guideline 2)
        try {
            $this->reactor->tick($effectiveTimeoutMs);
        } catch (\Throwable $e) {
            // Guideline 5: Node Isolation. A failure in one node (or the reactor) 
            // should not impact others if possible. But reactor failure is critical.
            $this->logger->error('Reactor tick failure', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // 2. Internal state processing for all clients
        $this->connectAttemptsThisTick = 0;
        $maxConnectAttempts = $this->options->maxConnectAttemptsPerTick;

        $keys = array_keys($this->clients);
        $count = count($keys);
        $startCursor = $this->reconnectCursor;
        for ($i = 0; $i < $count; $i++) {
            $index = ($startCursor + $i) % $count;
            $key = $keys[$index];
            $client = $this->clients[$key];
            try {
                $canConnect = $this->connectAttemptsThisTick < $maxConnectAttempts;
                if ($client->processTick($canConnect)) {
                    $this->connectAttemptsThisTick++;
                    // Advance cursor on every attempted connect to prevent starvation.
                    $this->reconnectCursor = ($index + 1) % $count;
                }
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
     * Alias for tickAll(0).
     */
    public function pollAll(): void
    {
        $this->tickAll(0);
    }

    private function normalizeRuntimeTimeoutMs(int $timeoutMs): int
    {
        $normalized = max(0, $timeoutMs);
        if ($normalized > 0) {
            $this->metrics->increment('ami_runtime_timeout_clamped_total', [
                'mode' => 'non_blocking',
            ]);
        }

        return 0;
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
                try {
                    $listener($event);
                } catch (\Throwable $e) {
                    $this->logger->error('Manager event listener failed', [
                        'server_key' => $event->serverKey,
                        'event_name' => $event->getName(),
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 2. Catch-all listeners
        foreach ($this->anyEventListeners as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                $this->logger->error('Manager any-event listener failed', [
                    'server_key' => $event->serverKey,
                    'event_name' => $event->getName(),
                    'exception' => $e->getMessage(),
                ]);
            }
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
        $this->validateHostnamePolicy($config, $options);

        $transport = new TcpTransport(
            $this->resolvedHostsByKey[$config->key] ?? $this->resolveHost($config, $options),
            $config->port,
            $options->connectTimeout,
            $options->writeBufferLimit,
            $options->maxBytesReadPerTick,
            $options->enforceIpEndpoints,
            $this->logger instanceof Logger ? $this->logger->withServerKey($config->key) : $this->logger,
            $this->metrics,
            [
                'server_key' => $config->key,
                'server_host' => $config->host,
            ]
        );

        $labels = [
            'server_key' => $config->key,
            'server_host' => $config->host,
        ];
        
        $correlationRegistry = new CorrelationRegistry(
            maxPending: $options->maxPendingActions,
            logger: $this->logger instanceof Logger ? $this->logger->withServerKey($config->key) : $this->logger,
            metrics: $this->metrics,
            labels: $labels
        );
        $actionIdGenerator = new ActionIdGenerator($config->key, maxActionIdLength: $options->maxActionIdLength);
        $correlation = new CorrelationManager($actionIdGenerator, $correlationRegistry);
        $eventQueue = new EventQueue($options->eventQueueCapacity, $this->metrics, $labels);
        $eventFilter = new EventFilter($options->allowedEvents, $options->blockedEvents);
        $parser = new Parser(maxFrameSize: $options->maxFrameSize);

        $client = new AmiClient(
            serverKey: $config->key,
            transport: $transport,
            correlation: $correlation,
            parser: $parser,
            connectionManager: new \Apn\AmiClient\Health\ConnectionManager(
                heartbeatInterval: $options->heartbeatInterval,
                maxConnectAttemptsPerTick: $options->maxConnectAttemptsPerTick,
                connectTimeout: (float) $options->connectTimeout,
                readTimeout: (float) $options->readTimeout,
                circuitFailureThreshold: $options->circuitFailureThreshold,
                circuitCooldown: (float) $options->circuitCooldown,
                circuitHalfOpenMaxProbes: $options->circuitHalfOpenMaxProbes,
                metrics: $this->metrics,
                logger: $this->logger instanceof Logger ? $this->logger->withServerKey($config->key) : $this->logger,
                labels: $labels,
            ),
            eventQueue: $eventQueue,
            eventFilter: $eventFilter,
            logger: $this->logger instanceof Logger ? $this->logger->withServerKey($config->key) : $this->logger,
            metrics: $this->metrics,
            host: $config->host,
            port: $config->port,
            maxFramesPerTick: $options->maxFramesPerTick,
            maxEventsPerTick: $options->maxEventsPerTick,
            eventDropLogIntervalMs: $options->eventDropLogIntervalMs,
            maxConnectAttemptsPerTick: $options->maxConnectAttemptsPerTick,
            readTimeout: (float) $options->readTimeout,
            circuitFailureThreshold: $options->circuitFailureThreshold,
            circuitCooldown: (float) $options->circuitCooldown,
            circuitHalfOpenMaxProbes: $options->circuitHalfOpenMaxProbes
        );

        if ($options->memoryLimit > 0) {
            $client->setMemoryLimit($options->memoryLimit);
        }

        if ($config->username !== null && $config->secret !== null) {
            $client->setCredentials($config->username, $config->secret);
        }

        return $client;
    }

    private function resolveHost(ServerConfig $config, ClientOptions $options): string
    {
        if (filter_var($config->host, FILTER_VALIDATE_IP) !== false) {
            return $config->host;
        }

        if ($options->enforceIpEndpoints) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid server "%s": host "%s" is not a literal IP while enforce_ip_endpoints is enabled.',
                $config->key,
                $config->host
            ));
        }

        return $this->resolveHostname($config->host, $config->key);
    }

    private function resolveHostname(string $host, string $serverKey): string
    {
        if (isset($this->resolvedHostCache[$host])) {
            return $this->resolvedHostCache[$host];
        }

        if ($this->hostnameResolver === null) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid server "%s": host "%s" requires a pre-resolved IP or an injected hostname resolver.',
                $serverKey,
                $host
            ));
        }

        $resolved = ($this->hostnameResolver)($host);
        if (!is_string($resolved) || $resolved === '' || filter_var($resolved, FILTER_VALIDATE_IP) === false) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid server "%s": hostname resolver returned invalid IP for host "%s".',
                $serverKey,
                $host
            ));
        }

        $this->resolvedHostCache[$host] = $resolved;
        return $resolved;
    }

    private function validateHostnamePolicy(ServerConfig $config, ?ClientOptions $resolvedOptions = null): void
    {
        $options = $resolvedOptions ?? ($config->options ?? $this->options);
        if (!$options->enforceIpEndpoints) {
            return;
        }

        if (filter_var($config->host, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Invalid server "%s": host "%s" is not a literal IP while enforce_ip_endpoints is enabled.',
            $config->key,
            $config->host
        ));
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
                if ($this->signalHandler !== null) {
                    ($this->signalHandler)($signal);
                }
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }
    }
}
