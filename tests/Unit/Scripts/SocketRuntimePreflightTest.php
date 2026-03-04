<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Apn\AmiClient\Scripts\SocketRuntimePreflight;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/lib/EnvironmentFailureClassifier.php';
require_once __DIR__ . '/../../../scripts/lib/SocketRuntimePreflight.php';

final class SocketRuntimePreflightTest extends TestCase
{
    public function test_preflight_returns_machine_readable_schema(): void
    {
        $result = SocketRuntimePreflight::run();

        $this->assertArrayHasKey('timestamp_utc', $result);
        $this->assertArrayHasKey('check', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('classification', $result);
        $this->assertArrayHasKey('signature', $result);
        $this->assertSame('tcp_bind_ephemeral', $result['check']);
        $this->assertContains($result['status'], ['PASS', 'FAIL']);
        $this->assertContains($result['classification'], ['RUNTIME_OK', 'SANDBOX_ENVIRONMENT', 'ACTIONABLE_DEFECT']);
    }
}

