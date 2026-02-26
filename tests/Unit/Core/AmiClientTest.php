<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Logoff;
use Apn\AmiClient\Core\EventQueue;
use Apn\AmiClient\Core\Logger;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Apn\AmiClient\Exceptions\InvalidConnectionStateException;
use Apn\AmiClient\Exceptions\ActionSendFailedException;
use Apn\AmiClient\Exceptions\BackpressureException;
use Psr\Log\AbstractLogger;

#[AllowMockObjectsWithoutExpectations]
class AmiClientTest extends TestCase
{
    public function testGetServerKey(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $this->assertEquals('node1', $client->getServerKey());
    }

    public function testPollCallsTickWithZero(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $transport->expects($this->once())
            ->method('tick')
            ->with(0);

        $client = new AmiClient('node1', $transport, $correlation);
        $client->poll();
    }

    public function testGetHealthStatus(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
        
        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Transitions from DISCONNECTED to READY (since no credentials)
        
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());
    }

    public function testReconnectionAttempt(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $transport->method('isConnected')->willReturn(false);
        $transport->expects($this->once())->method('open');
        
        $client->processTick(); // Trigger reconnection attempt
        $this->assertEquals(HealthStatus::CONNECTING, $client->getHealthStatus());
    }

    public function testHeartbeatFailureEscalation(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $transport->method('isConnected')->willReturn(true);
        $client->processTick();
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());

        // We need to trigger heartbeats. ConnectionManager default interval is 15s.
        // Let's use a custom ConnectionManager with short interval.
        $cm = new \Apn\AmiClient\Health\ConnectionManager(heartbeatInterval: 0.001);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm);
        $client->processTick();
        
        // We'll just manually trigger the failure
        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::READY_DEGRADED, $client->getHealthStatus());

        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::READY_DEGRADED, $client->getHealthStatus());

        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testOnEventWrapsInAmiEvent(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        $parser = new Parser();
        $client = new AmiClient('node1', $transport, $correlation, $parser);
        
        // Make the client healthy for event dispatch
        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Transitions to READY since no credentials
        
        $receivedEvent = null;
        $client->onAnyEvent(function(AmiEvent $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });
        
        $onDataCallback("Event: TestEvent\r\n\r\n");
        $client->processTick();
        
        $this->assertInstanceOf(AmiEvent::class, $receivedEvent);
        $this->assertEquals('node1', $receivedEvent->serverKey);
        $this->assertEquals('TestEvent', $receivedEvent->getName());
    }

    public function testLoginTimeout(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        // Use very short login timeout
        $cm = new \Apn\AmiClient\Health\ConnectionManager(loginTimeout: 0.001);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm);
        
        $transport->method('isConnected')->willReturn(true);
        $client->setCredentials('user', 'pass');
        
        // Trigger login
        $client->processTick();
        $this->assertEquals(HealthStatus::AUTHENTICATING, $client->getHealthStatus());
        
        // Wait and tick again
        usleep(2000);
        
        // Should trigger close() and transition to DISCONNECTED
        $transport->expects($this->once())->method('close');
        $client->processTick();
        
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testConnectTimeoutSchedulesReconnect(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());

        $cm = new \Apn\AmiClient\Health\ConnectionManager(connectTimeout: 0.001);
        $cm->setStatus(HealthStatus::CONNECTING);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm);

        $transport->method('isConnected')->willReturn(false);
        $transport->expects($this->once())->method('close');
        $transport->expects($this->never())->method('open');

        usleep(2000);
        $client->processTick();

        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testReadTimeoutSchedulesReconnect(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());

        $cm = new \Apn\AmiClient\Health\ConnectionManager(readTimeout: 0.001);
        $cm->setStatus(HealthStatus::READY);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm);

        $transport->method('isConnected')->willReturn(true);
        $transport->expects($this->once())->method('close');

        usleep(2000);
        $client->processTick();

        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testEventDispatchBlockedDuringAuthentication(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1', 'testinst'), new CorrelationRegistry());
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        $parser = new Parser();
        $client = new AmiClient('node1', $transport, $correlation, $parser);
        
        $receivedEvent = null;
        $client->onAnyEvent(function(AmiEvent $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });
        
        $transport->method('isConnected')->willReturn(true);
        $client->setCredentials('user', 'pass');
        
        // Trigger login, status becomes AUTHENTICATING
        $client->processTick();
        $this->assertEquals(HealthStatus::AUTHENTICATING, $client->getHealthStatus());
        
        // Receive an event during authentication
        $onDataCallback("Event: TestEvent\r\n\r\n");
        $client->processTick();
        
        // Should NOT be dispatched
        $this->assertNull($receivedEvent);
        
        // Now succeed login
        // Simulate Login response
        $onDataCallback("Response: Success\r\nActionID: node1:testinst:1\r\n\r\n");
        $client->processTick();
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());
        
        // Receive another event
        $onDataCallback("Event: PostLoginEvent\r\n\r\n");
        $client->processTick();
        
        // Should be dispatched
        $this->assertNotNull($receivedEvent);
        $this->assertEquals('PostLoginEvent', $receivedEvent->getName());
    }

    public function testListenerExceptionsDoNotBlockOtherListeners(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $client = new AmiClient('node1', $transport, $correlation, logger: new NullLogger());

        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Move to READY

        $specificCalls = 0;
        $anyCalls = 0;

        $client->onEvent('TestEvent', function () {
            throw new \RuntimeException('boom');
        });
        $client->onEvent('TestEvent', function () use (&$specificCalls) {
            $specificCalls++;
        });
        $client->onAnyEvent(function () use (&$anyCalls) {
            $anyCalls++;
        });

        $ref = new \ReflectionProperty(AmiClient::class, 'eventQueue');
        $eventQueue = $ref->getValue($client);
        $eventQueue->push(AmiEvent::create(new Event(['event' => 'TestEvent']), 'node1'));
        $eventQueue->push(AmiEvent::create(new Event(['event' => 'TestEvent']), 'node1'));

        $client->processTick();

        $this->assertEquals(2, $specificCalls);
        $this->assertEquals(2, $anyCalls);
    }

    public function testListenerExceptionsDoNotBreakDispatchWhenLoggerSerializationFails(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $output = [];
        $logger = new Logger(sinkWriter: function (string $line) use (&$output): int {
            $output[] = $line;
            return strlen($line);
        });
        $client = new AmiClient('node1', $transport, $correlation, logger: $logger);

        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Move to READY

        $specificCalls = 0;
        $anyCalls = 0;
        $invalidMessage = "bad \xC3\x28";

        $client->onEvent('TestEvent', function () use ($invalidMessage): void {
            throw new \RuntimeException($invalidMessage);
        });
        $client->onEvent('TestEvent', function () use (&$specificCalls): void {
            $specificCalls++;
        });
        $client->onAnyEvent(function () use (&$anyCalls): void {
            $anyCalls++;
        });

        $ref = new \ReflectionProperty(AmiClient::class, 'eventQueue');
        $eventQueue = $ref->getValue($client);
        $eventQueue->push(AmiEvent::create(new Event(['event' => 'TestEvent']), 'node1'));
        $eventQueue->push(AmiEvent::create(new Event(['event' => 'TestEvent']), 'node1'));

        $client->processTick();
        $logged = implode('', $output);

        $this->assertEquals(2, $specificCalls);
        $this->assertEquals(2, $anyCalls);
        $this->assertSame(HealthStatus::READY, $client->getHealthStatus());
        $this->assertStringContainsString('LOG_FALLBACK', $logged);
    }

    public function testPendingCallbackExceptionsAreIsolatedAndDoNotBreakSubsequentActions(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())
            ->method('increment')
            ->with(
                'ami_callback_exceptions_total',
                $this->callback(function (array $labels): bool {
                    return isset($labels['server_key'], $labels['server_host'], $labels['action'])
                        && $labels['server_key'] === 'node1'
                        && $labels['server_host'] === '127.0.0.1'
                        && $labels['action'] === 'Ping';
                })
            );

        $transport = $this->createMock(TransportInterface::class);
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function ($callback) use (&$onDataCallback): void {
            $onDataCallback = $callback;
        });
        $transport->method('isConnected')->willReturn(true);
        $transport->method('send')->willReturnCallback(static function (): void {});

        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::READY);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm, logger: $logger, metrics: $metrics, host: '127.0.0.1');

        $firstResolved = false;
        $secondResolved = false;

        $first = $client->send(new GenericAction('Ping'));
        $first->onComplete(function () {
            throw new \RuntimeException('callback boom');
        });
        $first->onComplete(function (?Throwable $e, ?Response $r) use (&$firstResolved): void {
            if ($e === null && $r?->isSuccess() === true) {
                $firstResolved = true;
            }
        });

        $second = $client->send(new GenericAction('Ping'));
        $second->onComplete(function (?Throwable $e, ?Response $r) use (&$secondResolved): void {
            if ($e === null && $r?->isSuccess() === true) {
                $secondResolved = true;
            }
        });

        $firstActionId = $first->getAction()->getActionId();
        $secondActionId = $second->getAction()->getActionId();

        $onDataCallback(
            "Response: Success\r\nActionID: {$firstActionId}\r\n\r\n" .
            "Response: Success\r\nActionID: {$secondActionId}\r\n\r\n"
        );
        $client->processTick();

        $this->assertTrue($firstResolved);
        $this->assertTrue($secondResolved);
        $this->assertSame(HealthStatus::READY, $client->getHealthStatus());

        $errorLog = null;
        foreach ($logger->records as $record) {
            if ($record['message'] === 'Pending action callback failed') {
                $errorLog = $record;
                break;
            }
        }

        $this->assertNotNull($errorLog);
        $this->assertSame('node1', $errorLog['context']['server_key']);
        $this->assertSame('Ping', $errorLog['context']['action_name']);
    }

    public function testSendFailsFastWhenNotReady(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $client = new AmiClient('node1', $transport, $correlation);

        $this->expectException(InvalidConnectionStateException::class);
        try {
            $client->send(new GenericAction('Ping'));
        } catch (InvalidConnectionStateException $e) {
            $this->assertEquals('node1', $e->getServerKey());
            $this->assertEquals(HealthStatus::DISCONNECTED->value, $e->getState());
            throw $e;
        }
    }

    public function testSendFailsFastDuringAuthentication(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::AUTHENTICATING);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm);

        $this->expectException(InvalidConnectionStateException::class);
        try {
            $client->send(new GenericAction('Ping'));
        } catch (InvalidConnectionStateException $e) {
            $this->assertEquals('node1', $e->getServerKey());
            $this->assertEquals(HealthStatus::AUTHENTICATING->value, $e->getState());
            throw $e;
        }
    }

    public function testBackpressureLogsQueueDepth(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $transport = $this->createMock(TransportInterface::class);
        $registry = new CorrelationRegistry(0);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), $registry);
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::READY);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm, logger: $logger);

        try {
            $client->send(new GenericAction('Ping'));
            $this->fail('Expected backpressure exception');
        } catch (\Apn\AmiClient\Exceptions\BackpressureException) {
            // expected
        }

        $record = $logger->records[0] ?? null;
        $this->assertNotNull($record);
        $this->assertEquals('Action rejected due to backpressure', $record['message']);
        $this->assertArrayHasKey('queue_depth', $record['context']);
        $this->assertArrayHasKey('queue_type', $record['context']);
    }

    public function testSendRollsBackPendingActionWhenTransportSendFails(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $registry = new CorrelationRegistry();
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), $registry);
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::READY);

        $transport->expects($this->once())
            ->method('send')
            ->willThrowException(new BackpressureException('write buffer full'));

        $transport->method('getPendingWriteBytes')->willReturn(1024);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm);

        try {
            $client->send(new GenericAction('Ping'));
            $this->fail('Expected ActionSendFailedException');
        } catch (ActionSendFailedException $e) {
            $this->assertSame('node1', $e->getServerKey());
            $this->assertSame('Ping', $e->getActionName());
            $this->assertNotSame('', $e->getActionId());
            $this->assertInstanceOf(BackpressureException::class, $e->getPrevious());
        }

        // No orphan pending action remains after send failure.
        $this->assertSame(0, $correlation->count());

        // No false timeout noise should appear after rollback.
        usleep(2000);
        $correlation->sweep();
        $this->assertSame(0, $correlation->count());
    }

    public function testEventDropLogsAreCoalescedByIntervalWithAccurateCounters(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $eventQueue = new EventQueue(1);
        $parser = new Parser();
        $onDataCallback = null;
        $now = 1000.0;

        $transport->method('onData')->willReturnCallback(function ($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        $transport->method('isConnected')->willReturn(true);

        $client = new AmiClient(
            'node1',
            $transport,
            $correlation,
            parser: $parser,
            eventQueue: $eventQueue,
            logger: $logger,
            maxEventsPerTick: 0,
            eventDropLogIntervalMs: 1000,
            clock: static function () use (&$now): float {
                return $now;
            }
        );

        $client->processTick();
        $onDataCallback("Event: TestEvent\r\n\r\nEvent: TestEvent\r\n\r\n");
        $client->processTick();
        $onDataCallback("Event: TestEvent\r\n\r\nEvent: TestEvent\r\n\r\n");
        $client->processTick();
        $now += 1.1;
        $client->processTick();

        $records = [];
        foreach ($logger->records as $candidate) {
            if ($candidate['message'] === 'Event drops summary due to queue capacity') {
                $records[] = $candidate;
            }
        }

        $this->assertCount(2, $records);
        $this->assertArrayHasKey('dropped_delta', $records[0]['context']);
        $this->assertArrayHasKey('queue_depth', $records[0]['context']);
        $this->assertArrayHasKey('queue_type', $records[0]['context']);
        $this->assertSame('event_queue', $records[0]['context']['queue_type']);
        $this->assertSame(1, $records[0]['context']['dropped_delta']);
        $this->assertSame(2, $records[1]['context']['dropped_delta']);
        $this->assertSame(3, $eventQueue->getDroppedEventsCount());
    }

    public function testHealthReporting(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $health = $client->health();
        
        $this->assertEquals('node1', $health['server_key']);
        $this->assertEquals(HealthStatus::DISCONNECTED->value, $health['status']);
        $this->assertFalse($health['connected']);
        $this->assertIsInt($health['memory_usage_bytes']);
        $this->assertEquals(0, $health['pending_actions']);
        $this->assertEquals(0, $health['dropped_events']);
    }

    public function testMetricsRecording(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::READY);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm, metrics: $metrics, host: '127.0.0.1');
        
        // Test latency recording
        $metrics->expects($this->once())
            ->method('record')
            ->with(
                'ami_action_latency_ms',
                $this->isFloat(),
                $this->callback(function($labels) {
                    return $labels['server_key'] === 'node1' && $labels['server_host'] === '127.0.0.1';
                })
            );
        
        $action = new GenericAction('Ping');
        $pending = $client->send($action);
        $pending->resolve(new Response(['Response' => 'Success']));
    }

    public function testCloseSendsLogoff(): void
    {
        $transport = new class implements TransportInterface {
            public bool $connected = true;
            public int $closeCalls = 0;
            public int $sendCalls = 0;
            public array $sentPayloads = [];
            private int $pendingBytes = 0;
            private $callback = null;

            public function open(): void {}
            public function close(bool $graceful = true): void { $this->closeCalls++; $this->connected = false; }
            public function send(string $data): void { $this->sendCalls++; $this->sentPayloads[] = $data; }
            public function tick(int $timeoutMs = 0): void {}
            public function onData(callable $callback): void { $this->callback = $callback; }
            public function isConnected(): bool { return $this->connected; }
            public function getPendingWriteBytes(): int { return $this->pendingBytes; }
            public function terminate(): void { $this->close(false); }
            public function setPendingWriteBytes(int $bytes): void { $this->pendingBytes = $bytes; }
        };
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());

        $client = new AmiClient('node1', $transport, $correlation);

        $transport->setPendingWriteBytes(10);
        $client->close();

        $this->assertSame(1, $transport->sendCalls);
        $this->assertStringContainsString('Action: Logoff', $transport->sentPayloads[0]);
        $this->assertSame(0, $transport->closeCalls);

        $client->processTick();
        $this->assertSame(0, $transport->closeCalls);

        $transport->setPendingWriteBytes(0);
        $client->processTick();
        $this->assertSame(1, $transport->closeCalls);
    }

    public function testCloseLogsStructuredTelemetryWhenLogoffEnqueueFails(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $transport = new class implements TransportInterface {
            public bool $connected = true;
            public int $closeCalls = 0;
            private int $pendingBytes = 0;
            public function open(): void {}
            public function close(bool $graceful = true): void { $this->closeCalls++; $this->connected = false; }
            public function send(string $data): void { throw new \RuntimeException('logoff send failed'); }
            public function tick(int $timeoutMs = 0): void {}
            public function onData(callable $callback): void {}
            public function isConnected(): bool { return $this->connected; }
            public function getPendingWriteBytes(): int { return $this->pendingBytes; }
            public function terminate(): void { $this->close(false); }
        };

        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $client = new AmiClient('node1', $transport, $correlation, logger: $logger);

        $client->close();

        $record = null;
        foreach ($logger->records as $candidate) {
            if ($candidate['message'] === 'Graceful logoff enqueue failed during shutdown') {
                $record = $candidate;
                break;
            }
        }

        $this->assertNotNull($record);
        $this->assertSame('warning', $record['level']);
        $this->assertSame('node1', $record['context']['server_key']);
        $this->assertSame('shutdown_logoff_enqueue_failed', $record['context']['reason']);
        $this->assertSame(\RuntimeException::class, $record['context']['exception_class']);
        $this->assertSame('logoff send failed', $record['context']['exception']);
    }
}
