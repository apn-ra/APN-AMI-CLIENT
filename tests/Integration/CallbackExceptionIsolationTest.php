<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Protocol\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Throwable;

final class CallbackExceptionIsolationTest extends TestCase
{
    public function test_callback_and_handler_failure_do_not_break_connection_or_subsequent_actions(): void
    {
        $transport = new class implements TransportInterface {
            private bool $connected = true;
            private ?\Closure $onData = null;
            private int $pendingBytes = 0;

            public function open(): void
            {
                $this->connected = true;
            }

            public function close(): void
            {
                $this->connected = false;
            }

            public function send(string $payload): void
            {
                $this->pendingBytes += strlen($payload);
            }

            public function isConnected(): bool
            {
                return $this->connected;
            }

            public function onData(callable $callback): void
            {
                $this->onData = \Closure::fromCallable($callback);
            }

            public function tick(int $timeoutMs = 0): void
            {
            }

            public function receive(string $data): void
            {
                if ($this->onData !== null) {
                    ($this->onData)($data);
                }
            }

            public function getPendingWriteBytes(): int
            {
                return $this->pendingBytes;
            }

            public function terminate(): void
            {
                $this->close();
            }
        };

        $throwingLogger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger callback handler failure');
            }
        };

        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $connectionManager = new ConnectionManager();
        $connectionManager->setStatus(HealthStatus::READY);

        $client = new AmiClient(
            'node1',
            $transport,
            $correlation,
            connectionManager: $connectionManager,
            logger: $throwingLogger,
            host: '127.0.0.1'
        );

        $firstResolved = false;
        $secondResolved = false;

        $first = $client->send(new GenericAction('Ping'));
        $first->onComplete(function (): void {
            throw new \RuntimeException('user callback failure');
        });
        $first->onComplete(function (?Throwable $e, ?Response $r) use (&$firstResolved): void {
            $firstResolved = $e === null && $r?->isSuccess() === true;
        });

        $second = $client->send(new GenericAction('Ping'));
        $second->onComplete(function (?Throwable $e, ?Response $r) use (&$secondResolved): void {
            $secondResolved = $e === null && $r?->isSuccess() === true;
        });

        $firstActionId = $first->getAction()->getActionId();
        $secondActionId = $second->getAction()->getActionId();

        $logFile = tempnam(sys_get_temp_dir(), 'ami-callback-handler-failure-');
        $this->assertIsString($logFile);
        $originalErrorLog = ini_get('error_log');
        $originalLogErrors = ini_get('log_errors');
        ini_set('log_errors', '1');
        ini_set('error_log', $logFile);

        try {
            $transport->receive(
                "Response: Success\r\nActionID: {$firstActionId}\r\n\r\n" .
                "Response: Success\r\nActionID: {$secondActionId}\r\n\r\n"
            );

            $client->processTick();
        } finally {
            ini_set('error_log', $originalErrorLog === false ? '' : (string) $originalErrorLog);
            ini_set('log_errors', $originalLogErrors === false ? '1' : (string) $originalLogErrors);
        }

        $contents = file_get_contents($logFile);
        if ($contents === false) {
            $contents = '';
        }
        @unlink($logFile);

        $this->assertTrue($firstResolved);
        $this->assertTrue($secondResolved);
        $this->assertSame(HealthStatus::READY, $client->getHealthStatus());
        $this->assertStringContainsString('pending_action_callback_exception', $contents);
        $this->assertStringContainsString('"server_key":"node1"', $contents);
        $this->assertStringContainsString('"action_id":"' . $firstActionId . '"', $contents);
        $this->assertStringContainsString('"callback_exception_class":"RuntimeException"', $contents);
    }
}
