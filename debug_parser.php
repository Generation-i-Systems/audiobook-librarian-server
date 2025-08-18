<?php

require_once 'vendor/autoload.php';

use App\Services\BookDirectoryParser;
use Illuminate\Support\Facades\File;

// Set up Laravel application
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Use the same test data path as the test
$testDataPath = storage_path('framework/testing/book_parser');

echo "Test data path: " . $testDataPath . "\n";

// Create a simple test directory structure
$simpleStructure = [
    'Fantasy' => [
        'Test Author' => [
            'Test Series' => [
                'Test Book.m4b' => ''
            ]
        ]
    ]
];

function createDirectories($basePath, $structure): void {
    foreach ($structure as $path => $content) {
        $fullPath = $basePath . '/' . $path;
        
        if (is_array($content)) {
            // It's a directory
            if (!File::exists($fullPath)) {
                File::makeDirectory($fullPath, 0755, true);
            }
            createDirectories($fullPath, $content);
        } else {
            // It's a file
            File::put($fullPath, '');
        }
    }
}

// Create the directory structure
createDirectories($testDataPath, $simpleStructure);

echo "Created directory structure:\n";
function showDirectoryStructure($path, $indent = 0): void {
    $files = File::files($path);
    $directories = File::directories($path);
    
    foreach ($files as $file) {
        echo str_repeat('  ', $indent) . $file->getFilename() . "\n";
    }
    
    foreach ($directories as $dir) {
        echo str_repeat('  ', $indent) . basename($dir) . "/\n";
        showDirectoryStructure($dir, $indent + 1);
    }
}

showDirectoryStructure($testDataPath);

// Check what files were actually created
echo "\nActual files created:\n";
exec("find {$testDataPath} -type f", $output);
foreach ($output as $file) {
    echo $file . "\n";
}
echo "\n";

// Create parser and parse directory
$parser = new BookDirectoryParser();
$books = $parser->parseDirectory($testDataPath);

echo "\nFound " . count($books) . " books:\n";
foreach ($books as $book) {
    echo "- " . ($book['title'] ?? 'Unknown') . " by " . 
         (is_array($book['author'] ?? null) ? implode(', ', $book['author']) : ($book['author'] ?? 'Unknown')) . "\n";
    echo "  Path: " . ($book['directoryPath'] ?? 'Unknown') . "\n";
    echo "  Series: " . ($book['seriesName'] ?? 'None') . "\n";
    echo "  Audio files: " . ($book['audioFileCount'] ?? 0) . "\n";
    echo "\n";
}

// Clean up
File::deleteDirectory($testDataPath . '/Fantasy');