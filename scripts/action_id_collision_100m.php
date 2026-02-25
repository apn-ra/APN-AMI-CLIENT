<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Apn\AmiClient\Correlation\ActionIdGenerator;

$workerCount = 10;
$iterationsPerWorker = 10_000_000;
$generators = [];

echo "Initializing $workerCount generators..." . PHP_EOL;
for ($i = 0; $i < $workerCount; $i++) {
    $generators[] = new ActionIdGenerator("server_key");
}

// Check for instance ID collisions first
$instanceIds = array_map(fn($g) => $g->getInstanceId(), $generators);
$uniqueInstanceIds = array_unique($instanceIds);
if (count($uniqueInstanceIds) !== $workerCount) {
    die("ERROR: InstanceID collision detected among $workerCount generators!" . PHP_EOL);
}
echo "No InstanceID collisions among $workerCount generators." . PHP_EOL;

echo "Generating 100M ActionIDs..." . PHP_EOL;
$startTime = microtime(true);
$total = 0;
foreach ($generators as $generator) {
    for ($j = 0; $j < $iterationsPerWorker; $j++) {
        $generator->next();
        $total++;
        if ($total % 10_000_000 === 0) {
            echo "Generated $total IDs..." . PHP_EOL;
        }
    }
}
$endTime = microtime(true);

echo "SUCCESS: Generated $total ActionIDs in " . round($endTime - $startTime, 2) . " seconds." . PHP_EOL;
echo "Average speed: " . round($total / ($endTime - $startTime) / 1000, 2) . " kID/s" . PHP_EOL;
