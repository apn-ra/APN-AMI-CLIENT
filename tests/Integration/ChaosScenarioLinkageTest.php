<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeEnvironment;

require_once __DIR__ . '/../Support/RuntimeEnvironment.php';

final class ChaosScenarioLinkageTest extends TestCase
{
    #[DataProvider('scenarioProvider')]
    public function test_scenario_runner_executes_linked_scenarios(string $scenarioFile): void
    {
        $command = sprintf(
            '%s %s --scenario=%s --duration-ms=900',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../Chaos/run_scenario.php'),
            escapeshellarg(__DIR__ . '/../../docs/ami-client/chaos/scenarios/' . $scenarioFile)
        );

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        $joined = implode("\n", $output);
        if ($exitCode !== 0 && RuntimeEnvironment::classifyExecutionOutput($joined) === 'SANDBOX_ENVIRONMENT') {
            $this->markTestSkipped("SANDBOX_ENVIRONMENT: scenario runtime unavailable for {$scenarioFile}");
        }
        $this->assertSame(0, $exitCode, "Scenario failed: {$scenarioFile}\n{$joined}");
        $this->assertMatchesRegularExpression('/classification=(RUNTIME_OK|SANDBOX_ENVIRONMENT|ACTIONABLE_DEFECT)/', $joined);
        $this->assertStringContainsString('result=PASS', $joined);
    }

    /**
     * @return list<array{0:string}>
     */
    public static function scenarioProvider(): array
    {
        return [
            ['s6-truncated-frame-recovery.json'],
            ['s7-garbage-desync-recovery.json'],
            ['s12-multi-server-fairness.json'],
        ];
    }
}
