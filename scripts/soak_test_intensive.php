<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Protocol\Parser;

$connectionsCount = 100; // Increased connections
$iterations = 50000;
$logger = new Logger();

$clients = [];

echo "Initializing $connectionsCount simulated clients..." . PHP_EOL;

for ($i = 0; $i < $connectionsCount; $i++) {
    $transport = new class implements TransportInterface {
        private $callback;
        public function open(): void {}
        public function close(): void {}
        public function send(string $data): void {}
        public function tick(int $timeoutMs = 0): void {}
        public function isConnected(): bool { return true; }
        public function onData(callable $callback): void { $this->callback = $callback; }
        public function terminate(): void {}
        public function getCallback(): ?callable { return $this->callback; }
    };
    
    $client = new AmiClient(
        "node_$i",
        $transport,
        new CorrelationRegistry(10000),
        new ActionIdGenerator("node_$i"),
        parser: new Parser(),
        logger: $logger
    );

    $client->onAnyEvent(function($event) {
        // No-op
    });

    $client->open();
    
    $clients[] = [
        'client' => $client,
        'transport' => $transport
    ];
}

gc_collect_cycles();
$startMemory = memory_get_usage();
echo "Start Memory: " . round($startMemory / 1024 / 1024, 2) . " MB" . PHP_EOL;

$startTime = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    foreach ($clients as $entry) {
        $client = $entry['client'];
        $transport = $entry['transport'];
        $callback = $transport->getCallback();
        
        if ($callback) {
            // Simulate traffic
            $callback("Event: TestEvent\r\nValue: " . $i . "\r\n\r\n");
            
            // Action processing
            $action = new \Apn\AmiClient\Protocol\GenericAction('Ping');
            $pending = $client->send($action);
            $actionId = $pending->getAction()->getActionId();
            
            $callback("Response: Success\r\nActionID: $actionId\r\n\r\n");
            
            $client->processTick();
        }
    }
    
        if ($i % 5000 === 0) {
        gc_collect_cycles();
        $currentMemory = memory_get_usage();
        $elapsed = microtime(true) - $startTime;
        
        $totalPending = 0;
        foreach ($clients as $entry) {
            $totalPending += $entry['client']->health()['pending_actions'];
        }

        echo "Iteration $i, Memory: " . round($currentMemory / 1024 / 1024, 2) . " MB, Total Pending: $totalPending, Elapsed: " . round($elapsed, 2) . "s" . PHP_EOL;
        
        if ($i > 0 && $currentMemory > $startMemory + 2 * 1024 * 1024) {
             die("ERROR: Potential memory leak detected! Growth: " . ($currentMemory - $startMemory) . " bytes" . PHP_EOL);
        }
    }
}

gc_collect_cycles();
$endMemory = memory_get_usage();
$totalElapsed = microtime(true) - $startTime;

echo "Final Memory: " . round($endMemory / 1024 / 1024, 2) . " MB" . PHP_EOL;
echo "Total Time: " . round($totalElapsed, 2) . "s" . PHP_EOL;

if ($endMemory <= $startMemory + 1024 * 1024) {
    echo "SUCCESS: Zero memory growth detected (within threshold)." . PHP_EOL;
} else {
    echo "WARNING: Final memory is significantly higher than start memory." . PHP_EOL;
}
