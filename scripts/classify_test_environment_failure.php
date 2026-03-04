<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/EnvironmentFailureClassifier.php';

use Apn\AmiClient\Scripts\EnvironmentFailureClassifier;

$options = getopt('', ['input::']);
$inputPath = $options['input'] ?? null;

if (is_string($inputPath) && $inputPath !== '') {
    $payload = file_get_contents($inputPath);
    if (!is_string($payload)) {
        fwrite(STDERR, "Unable to read input file: {$inputPath}" . PHP_EOL);
        exit(2);
    }
} else {
    $payload = stream_get_contents(STDIN);
    if (!is_string($payload)) {
        fwrite(STDERR, "Unable to read stdin payload." . PHP_EOL);
        exit(2);
    }
}

echo EnvironmentFailureClassifier::classify($payload) . PHP_EOL;
