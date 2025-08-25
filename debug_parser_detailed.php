<?php

require_once 'vendor/autoload.php';

use App\Services\BookDirectoryParser;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

// Set up Laravel application
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Use the same test data path as the test
$testDataPath = storage_path('framework/testing/book_parser');

echo "Test data path: " . $testDataPath . "\n";

// Create the test directory structure
$structure = [
    'Fantasy' => [
        'Brandon Sanderson' => [
            'Mistborn' => [
                'Mistborn 1 - The Final Empire.m4b' => '',
            ],
            'The Stormlight Archive' => [
                'The Way of Kings.m4b' => '',
                'Words of Radiance.mp3' => '',
                'Oathbringer (Michael Kramer).m4b' => '',
                'Rhythm of War [Graphic Audio].mp3' => '',
            ],
        ],
        'J.R.R. Tolkien' => [
            'The Lord of the Rings' => [
                'The Lord of the Rings 1 - The Fellowship of the Ring.m4b' => '',
                'The Lord of the Rings 2 - The Two Towers.m4b' => '',
                'The Lord of the Rings 3 - The Return of the King.m4b' => '',
            ],
        ],
    ],
    'Science Fiction' => [
        'Andy Weir' => [
            'The Martian [R.C. Bray].m4b' => '',
            'Project Hail Mary (narrated by Ray Porter).m4b' => '',
        ],
    ],
];

function createDirectories($basePath, $structure): void
{
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
createDirectories($testDataPath, $structure);

echo "Created directory structure:\n";
function showDirectoryStructure($path, $indent = 0): void
{
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

// Check each directory individually to see which ones contain audio files
echo "\nChecking each directory for audio files:\n";
function checkDirectoryForAudioFiles($path, $storageRoot): void
{
    echo "Checking directory: $path\n";

    // Check if this directory contains audio files directly
    $audioFiles = (new Finder())
        ->files()
        ->in($path)
        ->depth('== 0')
        ->name(['*.mp3', '*.m4b', '*.m4a', '*.aac', '*.flac', '*.wav', '*.ogg']);

    $count = iterator_count($audioFiles);
    echo "  Direct audio files: $count\n";

    // Check if any subdirectories contain audio files
    $subdirs = (new Finder())
        ->directories()
        ->in($path)
        ->depth('== 0');

    foreach ($subdirs as $subdir) {
        echo "  Subdirectory: " . $subdir->getFilename() . "/\n";

        $subAudioFiles = (new Finder())
            ->files()
            ->in($subdir->getPathname())
            ->depth('== 0')
            ->name(['*.mp3', '*.m4b', '*.m4a', '*.aac', '*.flac', '*.wav', '*.ogg']);

        $subCount = iterator_count($subAudioFiles);
        echo "    Audio files: $subCount\n";
    }
}

function traverseDirectories($path, $storageRoot): void
{
    checkDirectoryForAudioFiles($path, $storageRoot);

    $directories = File::directories($path);
    foreach ($directories as $dir) {
        traverseDirectories($dir, $storageRoot);
    }
}

traverseDirectories($testDataPath, $testDataPath);

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
File::deleteDirectory($testDataPath . '/Science Fiction');
