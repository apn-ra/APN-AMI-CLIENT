#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/EnvironmentFailureClassifier.php';
require_once __DIR__ . '/lib/SocketRuntimePreflight.php';

use Apn\AmiClient\Scripts\SocketRuntimePreflight;

$result = SocketRuntimePreflight::run();
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;

