<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\BookController;
use App\Services\GoogleBooksApiService;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class BookControllerCoverUrlTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function ensureBookFieldsMinimalBuildsCoverUrlUsingDirectoryPathAndBasename(): void
    {
        $documentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $googleBooks = Mockery::mock(GoogleBooksApiService::class);

        $controller = new BookController($documentStore, $googleBooks);

        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('ensureBookFieldsMinimal');
        $method->setAccessible(true);

        $book = [
            'id' => '1',
            'title' => 'Test',
            'directoryPath' => 'Genre/Author/Title',
            'coverImage' => 'cover_audible_123.jpg',
            'authors' => ['A'],
            'genres' => ['G'],
        ];

        /** @var array $result */
        $result = $method->invoke($controller, $book);

        $this->assertIsString($result['coverImage']);
        $this->assertStringContainsString('/cover/', $result['coverImage']);
        $this->assertStringContainsString('/cover/Genre/Author/Title/cover_audible_123.jpg', $result['coverImage']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ensureBookFieldsMinimalUpgradesGeneratedCoverUrlToHttps(): void
    {
        config(['app.url' => 'http://library.test']);
        URL::forceRootUrl('http://library.test');

        $documentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $googleBooks = Mockery::mock(GoogleBooksApiService::class);

        $controller = new BookController($documentStore, $googleBooks);

        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('ensureBookFieldsMinimal');
        $method->setAccessible(true);

        $book = [
            'id' => '1',
            'title' => 'Test',
            'directoryPath' => 'Genre/Author/Title',
            'coverImage' => 'cover_audible_123.jpg',
            'authors' => ['A'],
            'genres' => ['G'],
        ];

        /** @var array $result */
        $result = $method->invoke($controller, $book);

        $this->assertStringStartsWith('https://library.test/cover/', $result['coverImage']);
    }
}
