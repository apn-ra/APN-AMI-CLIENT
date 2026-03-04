<?php

declare(strict_types=1);

namespace Tests\RealPbx;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Exceptions\AmiTimeoutException;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Command;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\GetVar;
use Apn\AmiClient\Protocol\Hangup;
use Apn\AmiClient\Protocol\Originate;
use Apn\AmiClient\Protocol\PJSIPShowEndpoint;
use Apn\AmiClient\Protocol\PJSIPShowEndpoints;
use Apn\AmiClient\Protocol\Ping;
use Apn\AmiClient\Protocol\QueueStatus;
use Apn\AmiClient\Protocol\QueueSummary;
use Apn\AmiClient\Protocol\Redirect;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\SetVar;
use Apn\AmiClient\Transport\TcpTransport;
use Psr\Log\AbstractLogger;
use Throwable;

final class RealPbxRunner
{
    /** @var list<string> */
    private array $rawHeaderSample = [];

    /** @var list<array<string, mixed>> */
    private array $logs = [];

    /** @var array<string, string> */
    private array $discovered = [];

    /** @param array<int, array<string, mixed>> $manifest */
    public function __construct(
        private readonly RealPbxConfig $config,
        private readonly Redactor $redactor,
        private readonly array $manifest,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $start = microtime(true);

        $transport = new TapTransport(
            new TcpTransport(
                host: (string) $this->config->host,
                port: $this->config->port,
                connectTimeout: max(1, (int) ceil($this->config->connectTimeoutMs / 1000)),
                enforceIpEndpoints: false,
                labels: [
                    'server_key' => $this->config->serverKey,
                    'server_host' => (string) $this->config->host,
                ],
            ),
            $this->captureRawData(...),
        );

        $correlation = new CorrelationManager(
            new ActionIdGenerator($this->config->serverKey, maxActionIdLength: 64),
            new CorrelationRegistry(labels: ['server_key' => $this->config->serverKey]),
        );

        $logger = new class($this->logs, $this->redactor) extends AbstractLogger {
            /** @param list<array<string, mixed>> $logs */
            public function __construct(private array &$logs, private Redactor $redactor)
            {
            }

            /** @param mixed $level */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [
                    'at' => microtime(true),
                    'level' => (string) $level,
                    'message' => $this->redactor->redactString((string) $message),
                    'context' => $this->redactor->redactMixed($context),
                ];
            }
        };

        $client = new AmiClient(
            serverKey: $this->config->serverKey,
            transport: $transport,
            correlation: $correlation,
            logger: $logger,
            host: (string) $this->config->host,
            port: $this->config->port,
            readTimeout: max(3.0, ($this->config->authTimeoutMs + 2000) / 1000),
        );

        $client->setCredentials((string) $this->config->username, (string) $this->config->secret);

        $connectStart = microtime(true);
        try {
            $client->open();
        } catch (Throwable $e) {
            return [
                'started_at' => date('c'),
                'infra_status' => 'CONNECT_FAILED',
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'connect_ms' => null,
                'auth_ms' => null,
                'health' => $client->health(),
                'banner_seen' => false,
                'raw_header_sample' => [],
                'logs' => array_slice($this->logs, -20),
                'actions' => [],
                'infra_error' => [
                    'exception_class' => $e::class,
                    'message' => $this->redactor->redactString($e->getMessage()),
                ],
            ];
        }

        $connectMs = null;
        $authMs = null;
        $connectedAt = null;
        $ready = false;

        $readyDeadline = $connectStart + (($this->config->connectTimeoutMs + $this->config->authTimeoutMs + 1500) / 1000);

        while (microtime(true) < $readyDeadline) {
            $client->tick(25);

            if ($connectedAt === null && $client->isConnected()) {
                $connectedAt = microtime(true);
                $connectMs = (int) round(($connectedAt - $connectStart) * 1000);
            }

            if ($client->getHealthStatus() === HealthStatus::READY || $client->getHealthStatus() === HealthStatus::READY_DEGRADED) {
                $ready = true;
                $authMs = (int) round((microtime(true) - ($connectedAt ?? $connectStart)) * 1000);
                break;
            }
        }

        if (!$ready) {
            return [
                'started_at' => date('c'),
                'infra_status' => 'AUTH_OR_CONNECT_FAILED',
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'connect_ms' => $connectMs,
                'auth_ms' => $authMs,
                'health' => $client->health(),
                'banner_seen' => $this->bannerSeen(),
                'raw_header_sample' => array_slice($this->rawHeaderSample, 0, 10),
                'logs' => array_slice($this->logs, -20),
                'actions' => [],
            ];
        }

        $results = [];

        foreach ($this->manifest as $entry) {
            $results[] = $this->runAction($client, $entry);
        }

        try {
            $client->close();
            for ($i = 0; $i < 8; $i++) {
                $client->tick(10);
            }
        } catch (Throwable) {
            // Ignore close noise in report path.
        }

        return [
            'started_at' => date('c'),
            'infra_status' => 'READY',
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'connect_ms' => $connectMs,
            'auth_ms' => $authMs,
            'health' => $client->health(),
            'banner_seen' => $this->bannerSeen(),
            'raw_header_sample' => array_slice($this->rawHeaderSample, 0, 10),
            'logs' => array_slice($this->logs, -20),
            'actions' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function runAction(AmiClient $client, array $entry): array
    {
        $name = (string) ($entry['name'] ?? $entry['class'] ?? 'unknown');
        $enabled = (bool) ($entry['enabled'] ?? true);

        if (!$enabled) {
            return [
                'name' => $name,
                'status' => 'SKIP',
                'reason' => (string) ($entry['skip_reason'] ?? 'disabled'),
            ];
        }

        try {
            $action = $this->buildAction($entry);
        } catch (Throwable $e) {
            return [
                'name' => $name,
                'status' => 'SKIP',
                'reason' => 'Unable to build action: ' . $this->redactor->redactString($e->getMessage()),
            ];
        }

        if ($action === null) {
            return [
                'name' => $name,
                'status' => 'SKIP',
                'reason' => 'Missing prerequisite parameters (likely endpoint discovery).',
            ];
        }

        $done = false;
        $response = null;
        $events = [];
        $exception = null;

        $timeoutMs = (int) (($entry['completion']['timeout_ms'] ?? 3000));
        $sentAt = microtime(true);

        try {
            $pending = $client->send($action);
            $pending->onComplete(function (?Throwable $e, ?Response $r, array $ev) use (&$done, &$response, &$events, &$exception): void {
                $done = true;
                $response = $r;
                $events = $ev;
                $exception = $e;
            });
        } catch (Throwable $e) {
            return [
                'name' => $name,
                'status' => 'FAIL',
                'latency_ms' => (int) round((microtime(true) - $sentAt) * 1000),
                'reason' => 'send threw: ' . $this->redactor->redactString($e->getMessage()),
                'exception_class' => $e::class,
            ];
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (!$done && microtime(true) < $deadline) {
            $client->tick(25);
        }

        if (!$done) {
            return [
                'name' => $name,
                'status' => 'FAIL',
                'latency_ms' => (int) round((microtime(true) - $sentAt) * 1000),
                'reason' => 'silent hang / completion not reached before timeout window',
            ];
        }

        $latencyMs = (int) round((microtime(true) - $sentAt) * 1000);

        if ($exception !== null) {
            return [
                'name' => $name,
                'status' => $exception instanceof AmiTimeoutException ? 'FAIL' : 'FAIL',
                'latency_ms' => $latencyMs,
                'reason' => $this->redactor->redactString($exception->getMessage()),
                'exception_class' => $exception::class,
            ];
        }

        $responseType = is_string($response?->getHeader('Response')) ? (string) $response?->getHeader('Response') : 'Unknown';
        $eventNames = $this->eventNames($events);

        if ($name === 'PJSIPShowEndpoints') {
            $this->discoverEndpointFromEvents($events);
        }

        $terminalEvents = $entry['completion']['terminal_events'] ?? [];
        $terminalObserved = $terminalEvents === [] || count(array_intersect($terminalEvents, $eventNames)) > 0;

        $pass = in_array(strtolower($responseType), ['success', 'error'], true) && $terminalObserved;

        return [
            'name' => $name,
            'status' => $pass ? 'PASS' : 'FAIL',
            'latency_ms' => $latencyMs,
            'response' => $responseType,
            'events' => count($events),
            'event_names_sample' => array_slice($eventNames, 0, 8),
            'terminal_observed' => $terminalObserved,
            'normalized_sample' => $this->normalizedSample($response, $events),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function buildAction(array $entry): ?Action
    {
        $class = (string) $entry['class'];
        $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];

        return match ($class) {
            Ping::class => new Ping(),
            Command::class => new Command((string) ($params['command'] ?? 'core show version')),
            PJSIPShowEndpoints::class => new PJSIPShowEndpoints(),
            PJSIPShowEndpoint::class => $this->buildPjsipShowEndpoint($params),
            QueueSummary::class => new QueueSummary(isset($params['queue']) ? (string) $params['queue'] : null),
            QueueStatus::class => new QueueStatus(
                queue: isset($params['queue']) ? (string) $params['queue'] : null,
                member: isset($params['member']) ? (string) $params['member'] : null,
            ),
            GetVar::class => new GetVar(
                variable: (string) ($params['variable'] ?? 'FOO'),
                channel: isset($params['channel']) ? (string) $params['channel'] : null,
            ),
            SetVar::class => new SetVar(
                variable: (string) ($params['variable'] ?? 'FOO'),
                value: (string) ($params['value'] ?? 'BAR'),
                channel: isset($params['channel']) ? (string) $params['channel'] : null,
            ),
            Hangup::class => new Hangup(
                channel: (string) ($params['channel'] ?? 'PJSIP/100-00000001'),
            ),
            Originate::class => new Originate(
                channel: (string) ($params['channel'] ?? 'PJSIP/100'),
            ),
            Redirect::class => new Redirect(
                channel: (string) ($params['channel'] ?? 'PJSIP/100-00000001'),
                exten: (string) ($params['exten'] ?? '100'),
                context: (string) ($params['context'] ?? 'default'),
                priority: (int) ($params['priority'] ?? 1),
            ),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildPjsipShowEndpoint(array $params): ?PJSIPShowEndpoint
    {
        $endpoint = (string) ($params['endpoint'] ?? '');

        if ($endpoint === '__DISCOVERED_ENDPOINT__') {
            $endpoint = $this->discovered['endpoint'] ?? '';
        }

        if ($endpoint === '') {
            return null;
        }

        return new PJSIPShowEndpoint($endpoint);
    }

    private function captureRawData(string $chunk): void
    {
        if (count($this->rawHeaderSample) >= 10) {
            return;
        }

        $lines = preg_split('/\r?\n/', $chunk) ?: [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }

            if (str_starts_with($trim, 'Asterisk Call Manager/') || str_contains($trim, ':')) {
                $this->rawHeaderSample[] = $this->redactor->redactString($trim);
                if (count($this->rawHeaderSample) >= 10) {
                    return;
                }
            }
        }
    }

    private function bannerSeen(): bool
    {
        foreach ($this->rawHeaderSample as $line) {
            if (str_starts_with($line, 'Asterisk Call Manager/')) {
                return true;
            }
        }

        return false;
    }

    /** @param Event[] $events */
    private function discoverEndpointFromEvents(array $events): void
    {
        foreach ($events as $event) {
            $headers = $event->getHeaders();
            foreach (['objectname', 'endpoint', 'aor', 'id'] as $key) {
                if (isset($headers[$key]) && is_string($headers[$key]) && $headers[$key] !== '') {
                    $this->discovered['endpoint'] = $headers[$key];
                    return;
                }
            }
        }
    }

    /** @param Event[] $events */
    private function eventNames(array $events): array
    {
        $names = [];
        foreach ($events as $event) {
            $names[] = $event->getName();
        }

        return array_values(array_unique($names));
    }

    /** @param Event[] $events */
    private function normalizedSample(?Response $response, array $events): array
    {
        $sample = [
            'response_headers' => $response?->getHeaders() ?? [],
            'events' => [],
        ];

        $events = array_slice($events, 0, 3);
        foreach ($events as $event) {
            $sample['events'][] = [
                'name' => $event->getName(),
                'headers' => $event->getHeaders(),
            ];
        }

        /** @var array<string, mixed> $sample */
        return $this->redactor->redactMixed($sample);
    }
}
