<?php

declare(strict_types=1);

namespace tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

final class ReadmeArtifactReferencesTest extends TestCase
{
    public function test_readme_referenced_production_readiness_artifacts_exist(): void
    {
        $readmePath = __DIR__ . '/../../../README.md';
        self::assertFileExists($readmePath);

        $readme = (string) file_get_contents($readmePath);
        preg_match_all('/\\]\\((docs\\/ami-client\\/[^)]+)\\)/', $readme, $matches);
        $paths = array_unique($matches[1] ?? []);

        self::assertNotEmpty($paths, 'README must reference at least one docs/ami-client artifact.');
        foreach ($paths as $relativePath) {
            self::assertFileExists(__DIR__ . '/../../../' . $relativePath, "Missing referenced artifact: {$relativePath}");
        }
    }

    public function test_release_checklist_globbed_artifact_families_resolve_to_files(): void
    {
        $repoRoot = __DIR__ . '/../../../';
        $requiredGlobs = [
            'docs/ami-client/production-readiness/audits/*-production-readiness-score.md',
            'docs/ami-client/production-readiness/findings/*-findings.md',
            'docs/ami-client/production-readiness/reports/*-pr-remediation-report.md',
            'docs/ami-client/production-readiness/reports/*-pr-remediation-artifacts-index.md',
            'docs/ami-client/chaos/reports/*-final-chaos-suite-results.md',
        ];

        foreach ($requiredGlobs as $globPattern) {
            $matches = glob($repoRoot . $globPattern);
            self::assertIsArray($matches);
            self::assertNotEmpty($matches, "Expected at least one artifact for pattern: {$globPattern}");
        }

        self::assertFileExists(
            $repoRoot . 'docs/ami-client/production-readiness/decisions/accepted-chaos-outcome-template.md'
        );
    }
}
