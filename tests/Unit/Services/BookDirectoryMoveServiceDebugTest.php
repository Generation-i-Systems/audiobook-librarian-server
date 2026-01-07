<?php

namespace Tests\Unit\Services;

use App\Services\BookDirectoryMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookDirectoryMoveServiceDebugTest extends TestCase
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
    public function itDebugsAllFilesOutput(): void
    {
        $disk = Storage::disk('books');

        // Create source directory
        $disk->makeDirectory('Fantasy/Author/Book Name');
        $disk->put('Fantasy/Author/Book Name/Book Name.m4b', 'audio content');

        // Check what allFiles returns
        $files = $disk->allFiles('Fantasy/Author/Book Name');

        dump('allFiles result:', $files);
        dump('disk path:', $disk->path(''));

        foreach ($files as $file) {
            dump('File:', $file);
            dump('Basename:', basename($file));
        }

        $this->assertTrue(true);
    }
}
