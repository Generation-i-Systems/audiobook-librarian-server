<?php

namespace Tests;

use App\Services\BookImportService;
use Illuminate\Support\Facades\File;

class DebugScanTest extends TestCase
{
    public function testScanFiltering()
    {
        $service = app(BookImportService::class);
        $basePath = storage_path('test_scan');

        // Ensure structure
        if (!File::exists($basePath)) {
            File::makeDirectory($basePath, 0755, true);
            File::makeDirectory($basePath . '/Series', 0755, true);
            File::makeDirectory($basePath . '/Series/Book1', 0755, true);
            exec('truncate -s 12M ' . $basePath . '/Series/intro.mp3');
            exec('truncate -s 12M ' . $basePath . '/Series/Book1/chapter1.mp3');
        }

        $results = $service->scanForAudiobooks([$basePath]);

        echo "\nFound " . count($results) . " books:\n";
        foreach ($results as $book) {
            echo " - " . $book['path'] . "\n";
        }

        $paths = array_column($results, 'path');

        // We expect /Series/Book1 to be there
        $this->assertContains($basePath . '/Series/Book1', $paths);

        // We expect /Series to NOT be there (filtered out)
        $this->assertNotContains($basePath . '/Series', $paths, "Parent directory should be filtered out");
    }
}
