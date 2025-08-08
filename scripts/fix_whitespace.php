<?php

$files = [
    'app/Services/MySqlService.php',
    'app/Services/MongoService.php',
    'app/Http/Controllers/Admin/QueueController.php',
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

    // Fix 1: Consistent spacing around ! operators
    $content = preg_replace('/\s+!(?=\S)/', '!', $content); // Remove space before !
    $content = preg_replace('/!(?=\S)/', '!', $content); // Ensure no space after !

    // Fix 2: Consistent docblock formatting
    $content = str_replace(' * @inheritDoc', ' * {@inheritDoc}', $content);
    $content = str_replace('* @inheritDoc', '* {@inheritDoc}', $content);
    $content = str_replace('@inheritDoc', '{@inheritDoc}', $content);

    // Fix 3: Consistent string concatenation spacing
    $content = preg_replace('/([^\s])\.(?=[^\s])/', '$1 . ', $content); // Add spaces around .
    $content = preg_replace('/\.\s{2,}/', ' . ', $content); // Fix multiple spaces around .

    // Only write if changes were made
    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        echo "Fixed whitespace in: $file\n";
    } else {
        echo "No changes needed for: $file\n";
    }
}

echo "Whitespace fixes complete.\n";
