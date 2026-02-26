<?php

declare(strict_types=1);

namespace Tests\Performance;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
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
        if (!getenv('RUN_SOAK_TESTS')) {
            $this->markTestSkipped('Set RUN_SOAK_TESTS=1 to enable soak tests.');
        }

        $iterations = (int) (getenv('SOAK_ITERATIONS') ?: 10000);
        $connectionsCount = (int) (getenv('SOAK_CONNECTIONS') ?: 10);
        $warmupIterations = (int) (getenv('SOAK_WARMUP_ITERATIONS') ?: 500);
        $memoryToleranceBytes = (int) (getenv('SOAK_MEMORY_TOLERANCE_BYTES') ?: 10 * 1024 * 1024);
        $clients = [];

        $logger = $this->createStub(Logger::class);

        // Base memory after bootstrap
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        $startMemory = memory_get_usage();

        for ($i = 0; $i < $connectionsCount; $i++) {
            $transport = $this->createMock(TransportInterface::class);
            $transport->method('isConnected')->willReturn(true);
            
            $client = new AmiClient(
                "node_$i",
                $transport,
                new CorrelationManager(new ActionIdGenerator("node_$i"), new CorrelationRegistry(5000)),
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
            $client->processTick();

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
        for ($i = 0; $i < $warmupIterations; $i++) {
            $this->simulateTraffic($clients);
        }
        
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        $baselineMemory = memory_get_usage();

        // Main soak simulation
        for ($i = 0; $i < $iterations; $i++) {
            $this->simulateTraffic($clients);
            
            if ($i % 1000 === 0) {
                gc_collect_cycles();
                if (function_exists('gc_mem_caches')) {
                    gc_mem_caches();
                }
                $currentMemory = memory_get_usage();
                echo "Iteration $i, Memory: " . $currentMemory . PHP_EOL;
                // Check for growth relative to baseline
                $this->assertLessThan($baselineMemory + $memoryToleranceBytes, $currentMemory, "Significant memory growth detected at iteration $i");
            }
        }

        // Final cleanup and check
        unset($clients);
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        $endMemory = memory_get_usage();

        // End memory should be close to start memory
        $this->assertLessThan($startMemory + $memoryToleranceBytes, $endMemory, "Memory leak detected after cleanup");
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
