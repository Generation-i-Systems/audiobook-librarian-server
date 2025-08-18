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

// Check if directory exists
if (!File::exists($testDataPath)) {
    echo "Test directory does not exist\n";
    exit(1);
}

// Show directory structure
function showDirectoryStructure($path, $indent = 0) {
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

echo "Directory structure:\n";
showDirectoryStructure($testDataPath);

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