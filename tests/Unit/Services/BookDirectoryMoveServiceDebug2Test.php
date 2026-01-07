<?php

namespace Tests\Unit\Services;

use App\Services\BookDirectoryMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookDirectoryMoveServiceDebug2Test extends TestCase
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
    public function itDebugsSameDirectoryMove(): void
    {
        $disk = Storage::disk('books');

        // Create source directory
        $disk->makeDirectory('Fantasy/Author/Book Name');
        $disk->put('Fantasy/Author/Book Name/Book Name.m4b', 'audio content');

        // Move to same directory
        $result = $this->service->moveBookDirectoryContents(
            'Fantasy/Author/Book Name',
            'Fantasy/Author/Book Name'
        );

        dump('Move result:', $result);
        dump('Files in directory:', $disk->allFiles('Fantasy/Author/Book Name'));

        $this->assertTrue(true);
    }
}
