<?php

declare(strict_types=1);

namespace tests\Unit\Scripts;

use Apn\AmiClient\Scripts\EnvironmentFailureClassifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/lib/EnvironmentFailureClassifier.php';

final class EnvironmentFailureClassifierTest extends TestCase
{
    public function test_classifies_socket_bind_signature_as_sandbox_environment(): void
    {
        $output = 'stream_socket_server(): Unable to connect to tcp://127.0.0.1:0 (Success)';
        $this->assertSame('SANDBOX_ENVIRONMENT', EnvironmentFailureClassifier::classify($output));
    }

    public function test_classifies_non_environment_output_as_actionable_defect(): void
    {
        $output = 'Failed asserting that 2 is identical to 1.';
        $this->assertSame('ACTIONABLE_DEFECT', EnvironmentFailureClassifier::classify($output));
    }
}
