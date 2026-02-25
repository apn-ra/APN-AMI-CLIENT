<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ConfigLoader;

/**
 * Profile C: Embedded Tick Mode
 * 
 * The application calls tickAll() inside its own existing event or worker loop.
 * No dedicated AMI worker process is required.
 */

// 1. Load configuration
$config = [
    'servers' => [
        'node1' => [
            'host' => '127.0.0.1',
            'port' => 5038,
            'username' => 'admin',
            'secret' => 'secret',
        ],
    ],
];

// 2. Instantiate manager
$manager = ConfigLoader::load($config);

echo "Starting Application with Embedded AMI Tick...\n";

// 3. Existing application loop (e.g., ReactPHP, Amp, or just a custom loop)
while (true) {
    // Perform application logic...
    do_application_work();

    // drive AMI processing non-blockingly
    // timeout=0 ensures it doesn't wait if no I/O is ready
    $manager->tickAll(0);

    // Optional: small sleep to prevent 100% CPU if do_application_work() is too fast
    usleep(10000); 
}

function do_application_work(): void
{
    // ... your app code ...
}
