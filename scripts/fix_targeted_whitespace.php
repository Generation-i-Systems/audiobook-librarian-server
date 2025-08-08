<?php

/**
 * This script fixes specific whitespace issues in targeted files.
 * It only makes minimal, targeted changes to fix specific issues.
 */

$files = [
    'app/Http/Controllers/Admin/QueueController.php',
    'app/Services/MongoService.php',
    'app/Services/MySqlService.php',
    'tests/Mocks/MockDocumentStoreService.php',
    'app/Contracts/DocumentStoreServiceInterface.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }

    $content = file_get_contents($file);
    $originalContent = $content;
    $modified = false;

    // Fix 1: Fix docblock @inheritDoc to be consistent
    if (str_contains($content, '@inheritDoc')) {
        $content = str_replace('@inheritDoc', '{@inheritDoc}', $content);
    }

    // Fix 2: Fix spacing around ! operator
    $content = preg_replace('/\s+!(?=\S)/', '!', $content); // Remove space before !
    $content = str_replace('! ', '!', $content); // Remove space after !

    // Only save if changes were made
    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        echo "Fixed whitespace in: $file\n";
        $modified = true;
    } else {
        echo "No changes needed for: $file\n";
    }
}

echo "Targeted whitespace fixes complete.\n";
