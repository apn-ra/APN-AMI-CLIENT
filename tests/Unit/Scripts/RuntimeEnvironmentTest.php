<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeEnvironment;

require_once __DIR__ . '/../../Support/RuntimeEnvironment.php';

final class RuntimeEnvironmentTest extends TestCase
{
    public function test_classifies_socket_bind_signature_as_sandbox_environment(): void
    {
        $output = 'Unable to start fake AMI server: Success (0)';
        $this->assertSame('SANDBOX_ENVIRONMENT', RuntimeEnvironment::classifyExecutionOutput($output));
    }

    public function test_classifies_non_environment_signature_as_actionable_defect(): void
    {
        $output = 'Fatal error: Undefined array key';
        $this->assertSame('ACTIONABLE_DEFECT', RuntimeEnvironment::classifyExecutionOutput($output));
    }
}

