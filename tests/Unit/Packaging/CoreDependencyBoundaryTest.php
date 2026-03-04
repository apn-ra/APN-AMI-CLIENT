<?php

declare(strict_types=1);

namespace tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;

final class CoreDependencyBoundaryTest extends TestCase
{
    public function test_core_package_does_not_require_laravel_dependencies_mandatorily(): void
    {
        $composerPath = __DIR__ . '/../../../composer.json';
        self::assertFileExists($composerPath);

        $decoded = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
        $require = is_array($decoded['require'] ?? null) ? $decoded['require'] : [];

        foreach (array_keys($require) as $package) {
            $this->assertFalse(
                str_starts_with((string) $package, 'illuminate/'),
                'Laravel packages must not be mandatory core requirements.'
            );
        }
    }
}
