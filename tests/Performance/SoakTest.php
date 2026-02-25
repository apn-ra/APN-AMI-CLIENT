<?php

declare(strict_types=1);

namespace Tests\Performance;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Protocol\Parser;
use PHPUnit\Framework\TestCase;

class SoakTest extends TestCase
{
    /**
     * Simulates a soak test with active connections and traffic to verify memory stability.
     * Guideline 10: 24h Soak Test, Memory Stability.
     */
    public function test_memory_stability_under_load(): void
    {
        $iterations = 10000;
        $connectionsCount = 10;
        $clients = [];

        $logger = $this->createMock(Logger::class);

        // Base memory after bootstrap
        gc_collect_cycles();
        $startMemory = memory_get_usage();

        for ($i = 0; $i < $connectionsCount; $i++) {
            $transport = $this->createMock(TransportInterface::class);
            
            $client = new AmiClient(
                "node_$i",
                $transport,
                new CorrelationRegistry(5000), // Higher limit
                new ActionIdGenerator("node_$i"),
                parser: new Parser(),
                logger: $logger
            );

            // Register a listener to simulate processing
            $client->onAnyEvent(function() {
                // Simulate some work
                $str = str_repeat('a', 100);
            });

            // Trigger onData registration
            $client->open();

            // We need to capture the private onRawData callback
            $reflection = new \ReflectionClass($client);
            $onRawDataMethod = $reflection->getMethod('onRawData');
            
            $clients[] = [
                'client' => $client,
                'callback' => $onRawDataMethod->getClosure($client),
                'transport' => $transport
            ];
        }

        // Warm up
        for ($i = 0; $i < 500; $i++) {
            $this->simulateTraffic($clients);
        }
        
        gc_collect_cycles();
        $baselineMemory = memory_get_usage();

        // Main soak simulation
        for ($i = 0; $i < $iterations; $i++) {
            $this->simulateTraffic($clients);
            
            if ($i % 1000 === 0) {
                gc_collect_cycles();
                $currentMemory = memory_get_usage();
                echo "Iteration $i, Memory: " . $currentMemory . PHP_EOL;
                // Check for growth relative to baseline
                $this->assertLessThan($baselineMemory + 1024 * 1024, $currentMemory, "Significant memory growth detected at iteration $i");
            }
        }

        // Final cleanup and check
        unset($clients);
        gc_collect_cycles();
        $endMemory = memory_get_usage();

        // End memory should be close to start memory
        $this->assertLessThan($startMemory + 1024 * 1024, $endMemory, "Memory leak detected after cleanup");
    }

    /**
     * @param array<int, array{client: AmiClient, callback: callable, transport: TransportInterface}> $clients
     */
    private function simulateTraffic(array $clients): void
    {
        foreach ($clients as $entry) {
            $callback = $entry['callback'];
            $client = $entry['client'];
            
            // Simulate 5 events per tick
            for ($j = 0; $j < 5; $j++) {
                $callback("Event: TestEvent\r\nServer: node\r\nValue: " . mt_rand() . "\r\n\r\n");
            }
            
            // Simulate 1 action response per tick
            $action = new \Apn\AmiClient\Protocol\GenericAction('Ping');
            $pending = $client->send($action);
            
            $actionId = $pending->getAction()->getActionId();
            $callback("Response: Success\r\nActionID: $actionId\r\nPing: Pong\r\n\r\n");
            
            $client->tick(0);
            
            // Explicitly resolve/cleanup to avoid pending action buildup in simulation
            unset($pending);
            unset($action);
        }
    }
}
