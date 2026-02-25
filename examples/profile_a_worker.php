<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ConfigLoader;
use Apn\AmiClient\Events\AmiEvent;

/**
 * Profile A: Pure PHP Worker
 * 
 * Manual instantiation of AmiClientManager and manual tick() loop.
 * Lifecycle managed by external tools like Supervisor or Systemd.
 */

// 1. Load configuration (usually from a file or env)
$config = [
    'default' => 'node1',
    'servers' => [
        'node1' => [
            'host' => '127.0.0.1',
            'port' => 5038,
            'username' => 'admin',
            'secret' => 'secret',
        ],
        'node2' => [
            'host' => '192.168.1.10',
            'port' => 5038,
            'username' => 'admin',
            'secret' => 'secret',
        ],
    ],
    'options' => [
        'connect_timeout' => 5,
    ],
];

// 2. Instantiate AmiClientManager via ConfigLoader (or manually)
$manager = ConfigLoader::load($config);

// 3. Register global event listeners
$manager->onAnyEvent(function (AmiEvent $event) {
    echo sprintf(
        "[%s] Received Event: %s from %s\n",
        date('Y-m-d H:i:s'),
        $event->getName(),
        $event->getServerKey()
    );
});

// 4. Handle OS signals for graceful shutdown
$manager->registerSignalHandlers();

echo "Starting Profile A Pure PHP Worker loop...\n";

// 5. The manual tick() loop
while (true) {
    // drive I/O and protocol parsing for all servers
    // 100ms timeout for stream_select()
    $manager->tickAll(100);
}
