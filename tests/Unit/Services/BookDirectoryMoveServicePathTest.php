<?php

namespace Tests\Unit\Services;

use App\Services\BookDirectoryMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookDirectoryMoveServicePathTest extends TestCase
{
    use RefreshDatabase;

    protected BookDirectoryMoveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('books');
        $this->service = new BookDirectoryMoveService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDebugsPathCalculations(): void
    {
        $disk = Storage::disk('books');

        // Simulate the user's scenario
        $oldDirectoryPath = 'Fantasy/Author/Old Name';
        $newDirectoryPath = 'Fantasy/Author/01 The Way of Shadows';

        // Create source directory
        $disk->makeDirectory($oldDirectoryPath);
        $disk->put($oldDirectoryPath . '/1. Brent Weeks - Night Angel 01 - The Way of Shadows.m4b', 'audio content');
        $disk->put($oldDirectoryPath . '/cover.jpg', 'cover content');
        $disk->put($oldDirectoryPath . '/librarian.json', '{"title": "Test"}');

        // Check what allFiles returns
        $files = $disk->allFiles($oldDirectoryPath);

        dump('Old directory path:', $oldDirectoryPath);
        dump('New directory path:', $newDirectoryPath);
        dump('Files from allFiles:', $files);

        foreach ($files as $file) {
            dump('Processing file:', $file);

            $startsWithCheck = Str::startsWith($file, $oldDirectoryPath . '/');
            dump('  Starts with check:', $startsWithCheck);
            dump('  Checking against:', $oldDirectoryPath . '/');

            if ($startsWithCheck) {
                $relative = Str::after($file, $oldDirectoryPath . '/');
                dump('  Relative path (after):', $relative);
            } else {
                $relative = basename($file);
                dump('  Relative path (basename):', $relative);
            }

            $target = rtrim($newDirectoryPath, '/') . '/' . ltrim($relative, '/');
            dump('  Target path:', $target);

            $targetDir = trim((string) dirname($target), '/');
            dump('  Target directory:', $targetDir);
            dump('---');
        }

        $this->assertTrue(true);
    }
}
