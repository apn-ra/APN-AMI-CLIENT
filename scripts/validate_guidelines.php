<?php

declare(strict_types=1);

$exitCode = 0;
$srcDir = realpath(__DIR__ . '/../src');
$coreDir = realpath($srcDir . '/Core');
$protocolDir = realpath($srcDir . '/Protocol');

function error(string $message): void {
    global $exitCode;
    echo "ERROR: $message\n";
    $exitCode = 1;
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($files as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getRealPath());
    $relativePath = str_replace(realpath(__DIR__ . '/../'), '', $file->getRealPath());

    // 1. declare(strict_types=1);
    if (!str_contains($content, 'declare(strict_types=1);')) {
        error("Missing strict_types in $relativePath");
    }

    // 2. No Illuminate in Core/Protocol
    if (str_starts_with($file->getRealPath(), $coreDir) || str_starts_with($file->getRealPath(), $protocolDir)) {
        if (preg_match('/use\s+Illuminate\\\\/i', $content)) {
            error("Forbidden Illuminate import in $relativePath");
        }
    }

    // 3. No sleep/usleep
    if (preg_match('/\bsleep\(|\busleep\(|\bnanosleep\(/i', $content)) {
        error("Forbidden sleep/usleep call in $relativePath");
    }

    // 4. No static mutable state (simple regex)
    if (preg_match('/private\s+static\s+\$/i', $content) || preg_match('/protected\s+static\s+\$/i', $content) || preg_match('/public\s+static\s+\$/i', $content)) {
        // Exclude constants or legitimately static things if needed, but guidelines say "Static variables for state storage are strictly forbidden"
        error("Potential static mutable state in $relativePath");
    }
}

if ($exitCode === 0) {
    echo "Guideline validation passed!\n";
}
exit($exitCode);
