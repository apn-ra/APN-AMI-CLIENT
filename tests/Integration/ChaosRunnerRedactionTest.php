<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeEnvironment;

require_once __DIR__ . '/../Support/RuntimeEnvironment.php';

final class ChaosRunnerRedactionTest extends TestCase
{
    public function test_runner_preview_redacts_sensitive_fields(): void
    {
        $scenario = [
            'id' => 'S-REDACT',
            'name' => 'runner-redaction',
            'client_settings' => [
                'timeout_ms' => 800,
                'parser_buffer_cap' => 2097152,
                'max_frame_size' => 1048576,
            ],
            'servers' => [[
                'key' => 'node-a',
                'script' => [[
                    'type' => 'send_frame',
                    'at_ms' => 0,
                    'frame' => "Response: Error\\r\\nActionID: redact:1\\r\\nSecret: super-secret",
                    'delimiter' => "\\r\\n\\r\\n",
                ]],
            ]],
            'expectations' => ['pending_action_failed'],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'chaos-redaction-');
        $this->assertIsString($tmp);
        file_put_contents($tmp, json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = sprintf(
            '%s %s --scenario=%s --duration-ms=800',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../Chaos/run_scenario.php'),
            escapeshellarg($tmp)
        );
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        @unlink($tmp);

        $joined = implode("\n", $output);
        if ($exitCode !== 0 && RuntimeEnvironment::classifyExecutionOutput($joined) === 'SANDBOX_ENVIRONMENT') {
            $this->markTestSkipped('SANDBOX_ENVIRONMENT: chaos runner socket runtime unavailable.');
        }
        $this->assertSame(0, $exitCode, $joined);
        $this->assertMatchesRegularExpression('/classification=(RUNTIME_OK|SANDBOX_ENVIRONMENT|ACTIONABLE_DEFECT)/', $joined);
        $this->assertStringContainsString('result=PASS', $joined);
        $this->assertStringNotContainsString('super-secret', $joined);
        $this->assertStringContainsString('Secret: ********', $joined);

        if (preg_match('/metrics_file=(.+)/', $joined, $matches) === 1) {
            $metricsPath = trim($matches[1]);
            $this->assertFileExists($metricsPath);
            $metrics = (string) file_get_contents($metricsPath);
            $this->assertStringContainsString('- Runtime Classification: `RUNTIME_OK`', $metrics);
        } else {
            $this->fail('metrics_file was not emitted by chaos runner output.');
        }
    }
}
