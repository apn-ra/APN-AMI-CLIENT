<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Protocol\Logoff;

$sentData = '';

$transport = new class($sentData) implements TransportInterface {
    public function __construct(public string &$sentData) {}
    public function open(): void {}
    public function close(): void {}
    public function send(string $data): void { $this->sentData .= $data; }
    public function tick(int $timeoutMs = 0): void {}
    public function isConnected(): bool { return true; }
    public function onData(callable $callback): void {}
    public function terminate(): void {}
};

$client = new AmiClient(
    "node",
    $transport,
    new CorrelationRegistry(),
    new ActionIdGenerator("node")
);

echo "Closing client..." . PHP_EOL;
$client->close();

if (str_contains($sentData, 'Action: Logoff')) {
    echo "SUCCESS: Logoff action detected in transport." . PHP_EOL;
} else {
    die("ERROR: Logoff action NOT detected in transport!" . PHP_EOL);
}

if (str_contains($sentData, 'ActionID: node:')) {
    echo "SUCCESS: ActionID detected in Logoff action." . PHP_EOL;
} else {
    die("ERROR: ActionID NOT detected in Logoff action!" . PHP_EOL);
}
