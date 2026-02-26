<?php

declare(strict_types=1);

namespace Tests\Performance;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\EventQueue;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FloodSimulationTest extends TestCase
{
    public function test_flood_simulation_drop_policy_and_fairness(): void
    {
        $normalLoad = 1000;
        $floodLoad = $normalLoad * 10;
        $capacity = $normalLoad * 5;
        $maxEventsPerTick = $normalLoad;
        $maxFramesPerTick = $floodLoad;

        $logger = $this->createStub(LoggerInterface::class);

        ['client' => $clientA, 'onData' => $onDataA] = $this->createClient(
            'node-a',
            $capacity,
            $maxFramesPerTick,
            $maxEventsPerTick,
            $logger
        );

        ['client' => $clientB, 'onData' => $onDataB] = $this->createClient(
            'node-b',
            $capacity,
            $maxFramesPerTick,
            $maxEventsPerTick,
            $logger
        );

        $receivedA = 0;
        $receivedB = 0;

        $clientA->onAnyEvent(function (AmiEvent $event) use (&$receivedA): void {
            $receivedA++;
        });

        $clientB->onAnyEvent(function (AmiEvent $event) use (&$receivedB): void {
            $receivedB++;
        });

        for ($i = 0; $i < $floodLoad; $i++) {
            $onDataA("Event: FloodEvent\r\n\r\n");
        }

        for ($i = 0; $i < $normalLoad; $i++) {
            $onDataB("Event: NormalEvent\r\n\r\n");
        }

        $manager = new AmiClientManager(new ServerRegistry(), new ClientOptions());
        $manager->addClient('node-a', $clientA);
        $manager->addClient('node-b', $clientB);
        $manager->tickAll(0);

        $this->assertSame($maxEventsPerTick, $receivedA);
        $this->assertSame($normalLoad, $receivedB);

        $healthA = $clientA->health();
        $healthB = $clientB->health();

        $this->assertSame($floodLoad - $capacity, $healthA['dropped_events']);
        $this->assertSame(0, $healthB['dropped_events']);
    }

    /**
     * @return array{client: AmiClient, onData: callable(string): void}
     */
    private function createClient(
        string $serverKey,
        int $capacity,
        int $maxFramesPerTick,
        int $maxEventsPerTick,
        LoggerInterface $logger
    ): array {
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('isConnected')->willReturn(true);

        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function (callable $callback) use (&$onDataCallback): void {
            $onDataCallback = $callback;
        });

        $client = new AmiClient(
            $serverKey,
            $transport,
            new CorrelationManager(new ActionIdGenerator($serverKey), new CorrelationRegistry()),
            eventQueue: new EventQueue($capacity),
            logger: $logger,
            maxFramesPerTick: $maxFramesPerTick,
            maxEventsPerTick: $maxEventsPerTick
        );

        if (!is_callable($onDataCallback)) {
            throw new \RuntimeException('Transport onData callback was not registered.');
        }

        return ['client' => $client, 'onData' => $onDataCallback];
    }
}
