<?php

declare(strict_types=1);

namespace tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

final class ChaosArtifactConsistencyTest extends TestCase
{
    public function test_latest_final_chaos_artifact_is_not_older_than_latest_suite_report(): void
    {
        $repoRoot = __DIR__ . '/../../../';

        $latestFinal = $this->latestFile($repoRoot . 'docs/ami-client/chaos/reports/*-final-chaos-suite-results.md');
        $latestSuite = $this->latestNonFinalSuiteReport($repoRoot . 'docs/ami-client/chaos/reports/*-chaos-suite-results.md');

        self::assertNotNull($latestFinal, 'No final chaos suite report found.');
        self::assertNotNull($latestSuite, 'No non-final chaos suite report found.');

        $latestFinalStamp = $this->extractStamp((string) $latestFinal);
        $latestSuiteStamp = $this->extractStamp((string) $latestSuite);

        self::assertNotNull($latestFinalStamp, 'Final chaos report filename does not include UTC stamp.');
        self::assertNotNull($latestSuiteStamp, 'Chaos suite report filename does not include UTC stamp.');
        self::assertGreaterThanOrEqual(
            $latestSuiteStamp,
            $latestFinalStamp,
            sprintf(
                'Latest final chaos report (%s) is older than latest suite report (%s). Regenerate final artifact.',
                basename((string) $latestFinal),
                basename((string) $latestSuite)
            )
        );
    }

    private function latestNonFinalSuiteReport(string $pattern): ?string
    {
        $matches = glob($pattern);
        if (!is_array($matches) || $matches === []) {
            return null;
        }

        $candidates = array_values(array_filter($matches, static function (string $path): bool {
            return !str_contains($path, '-final-chaos-suite-results.md');
        }));
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (string $a, string $b): int {
            return strcmp(basename($b), basename($a));
        });

        return $candidates[0] ?? null;
    }

    private function latestFile(string $pattern): ?string
    {
        $matches = glob($pattern);
        if (!is_array($matches) || $matches === []) {
            return null;
        }

        usort($matches, static function (string $a, string $b): int {
            return strcmp(basename($b), basename($a));
        });

        return $matches[0] ?? null;
    }

    private function extractStamp(string $path): ?string
    {
        if (preg_match('/(\d{8}-\d{6}Z)-/', basename($path), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
