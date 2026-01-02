#!/usr/bin/env php
<?php

/**
 * Reorganize tests by category into separate folders
 *
 * Old structure:
 *   tests/Feature/Api/, tests/Unit/Controllers/Api/
 *
 * New structure:
 *   tests/Api/Feature/, tests/Api/Unit/
 */

$mappings = [
    // API Tests
    'tests/Feature/Api' => 'tests/Api/Feature',
    'tests/Unit/Controllers/Api' => 'tests/Api/Unit/Controllers',

    // Web/Admin Tests
    'tests/Feature/Controllers/Admin' => 'tests/Web/Feature/Controllers',
    'tests/Feature/Admin' => 'tests/Web/Feature',
    'tests/Unit/Controllers/Admin' => 'tests/Web/Unit/Controllers',
    'tests/Unit/Views' => 'tests/Web/Unit/Views',

    // CLI Tests
    'tests/Feature/Commands' => 'tests/Cli/Feature',
    'tests/Unit/Commands' => 'tests/Cli/Unit',
    'tests/Unit/Console/Commands' => 'tests/Cli/Unit/Console',

    // Core/Service Tests
    'tests/Unit/Services' => 'tests/Core/Unit/Services',
    'tests/Feature/Services' => 'tests/Core/Feature/Services',
    'tests/Unit/Traits' => 'tests/Core/Unit/Traits',
    'tests/Unit/Support' => 'tests/Core/Unit/Support',

    // Import Tests - collect all Import-related tests
    'tests/Unit/Import' => 'tests/Import/Unit',
];

$baseDir = __DIR__ . '/..';
$moved = [];
$errors = [];

echo "🔄 Test Reorganization Script\n";
echo "============================\n\n";

// Create new directories
foreach ($mappings as $dest) {
    $fullDest = "$baseDir/$dest";
    if (!is_dir($fullDest)) {
        echo "📁 Creating directory: $dest\n";
        mkdir($fullDest, 0755, true);
    }
}

// Move files
foreach ($mappings as $source => $dest) {
    $fullSource = "$baseDir/$source";
    $fullDest = "$baseDir/$dest";

    if (!is_dir($fullSource)) {
        echo "⚠️  Source not found: $source\n";
        continue;
    }

    echo "\n📦 Moving: $source → $dest\n";

    // Get all PHP files recursively
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullSource, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $relativePath = str_replace($fullSource . '/', '', $file->getPathname());
            $destFile = $fullDest . '/' . $relativePath;
            $destDir = dirname($destFile);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            // Read file content to update namespace
            $content = file_get_contents($file->getPathname());

            // Update namespace
            $oldNamespace = str_replace('/', '\\', 'Tests/' . ltrim($source, 'tests/'));
            $newNamespace = str_replace('/', '\\', 'Tests/' . ltrim($dest, 'tests/'));

            $content = preg_replace(
                '/namespace ' . preg_quote($oldNamespace, '/') . '(\\\\[^;]+)?;/',
                'namespace ' . $newNamespace . '$1;',
                $content
            );

            // Write to new location
            file_put_contents($destFile, $content);

            echo "  ✓ $relativePath\n";
            $moved[] = $file->getPathname();
        }
    }
}

// Handle Import-related tests (scattered across directories)
echo "\n📦 Collecting Import-related tests...\n";
$importTestsFound = 0;

$allTests = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator("$baseDir/tests", RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($allTests as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        if (strpos($file->getFilename(), 'Import') !== false) {
            // This is an import-related test
            $relativePath = str_replace("$baseDir/tests/", '', $file->getPathname());

            // Skip if already moved
            if (in_array($file->getPathname(), $moved)) {
                continue;
            }

            // Determine if Feature or Unit
            $isFeature = strpos($relativePath, 'Feature/') !== false;
            $destBase = $isFeature ? 'tests/Import/Feature' : 'tests/Import/Unit';

            // Preserve subdirectory structure
            $subPath = basename($file->getPathname());
            if (preg_match('#(Feature|Unit)/(.+)/#', $relativePath, $matches)) {
                $subPath = $matches[2] . '/' . basename($file->getPathname());
            }

            $destFile = "$baseDir/$destBase/$subPath";
            $destDir = dirname($destFile);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $content = file_get_contents($file->getPathname());

            // Update namespace to Tests\Import\Feature or Tests\Import\Unit
            $content = preg_replace(
                '/namespace Tests\\\\(Feature|Unit).*?;/',
                'namespace Tests\\Import\\\\$1;',
                $content
            );

            file_put_contents($destFile, $content);
            echo "  ✓ Import: $relativePath → $destBase/$subPath\n";
            $importTestsFound++;
            $moved[] = $file->getPathname();
        }
    }
}

echo "\n✅ Reorganization complete!\n";
echo "   Moved " . count($moved) . " test files\n";
echo "   Found $importTestsFound import tests\n\n";

echo "⚠️  Next steps:\n";
echo "   1. Update composer.json autoload-dev\n";
echo "   2. Run: composer dump-autoload\n";
echo "   3. Update workflow files with new paths\n";
echo "   4. Run: php artisan test\n";
echo "   5. Remove old empty directories\n\n";
