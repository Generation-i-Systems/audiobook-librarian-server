<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\BookStorageUnavailableException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BookStorageUnavailableExceptionTest extends TestCase
{
    public function testIsA503HttpExceptionWhoseMessageDoesNotLeakThePath(): void
    {
        $root = '/media/audiobooks/books';
        $e = BookStorageUnavailableException::forRoot($root);

        $this->assertInstanceOf(HttpException::class, $e);
        $this->assertSame(503, $e->getStatusCode());
        $this->assertStringNotContainsString($root, $e->getMessage());
        $this->assertSame($root, $e->root);
    }

    public function testApiRequestsRenderAJson503(): void
    {
        Route::get('/api/_storage-unavailable-test', function () {
            throw BookStorageUnavailableException::forRoot('/media/audiobooks/books');
        });

        $response = $this->getJson('/api/_storage-unavailable-test');

        $response->assertStatus(503);
        $response->assertJson(['error' => true, 'message' => BookStorageUnavailableException::MESSAGE]);
    }

    public function testWebRequestsRenderA503NotA500(): void
    {
        Route::get('/_storage-unavailable-test', function () {
            throw BookStorageUnavailableException::forRoot('/media/audiobooks/books');
        });

        $response = $this->get('/_storage-unavailable-test');

        $response->assertStatus(503);
        $response->assertSee(BookStorageUnavailableException::MESSAGE);
        $response->assertDontSee('/media/audiobooks/books');
        $response->assertDontSee('RuntimeException');
        $response->assertDontSee('Stack trace');
    }
}
