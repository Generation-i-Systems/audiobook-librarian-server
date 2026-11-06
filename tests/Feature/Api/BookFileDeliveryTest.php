<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use Illuminate\Support\Facades\Storage;

/**
 * FILE_DELIVERY hands book file bytes to the web server (nginx X-Accel-Redirect, Apache/lighttpd
 * X-Sendfile) after the same checks PHP streaming does; the default keeps streaming through PHP.
 */
class BookFileDeliveryTest extends ApiTestCase
{
    private Book $book;

    private string $directory = 'Fantasy/Some Author/Book #1';

    private string $fileName = 'Part 01 [100%].m4b';

    private string $content = 'fake-audio-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('books');
        Storage::disk('books')->put($this->directory . '/' . $this->fileName, $this->content);
        $this->book = Book::factory()->create(['directory_path' => $this->directory]);
    }

    private function fileUrl(): string
    {
        return '/api/v1/books/' . $this->book->id . '/download/' . rawurlencode($this->fileName);
    }

    public function testDefaultDeliveryStreamsTheFileThroughPhp(): void
    {
        $response = $this->get($this->fileUrl());

        $response->assertOk();
        $response->assertHeaderMissing('X-Accel-Redirect');
        $response->assertHeaderMissing('X-Sendfile');
        $this->assertEquals($this->content, $response->streamedContent());
    }

    public function testXAccelRedirectNamesTheFileUnderTheInternalLocation(): void
    {
        config(['file_delivery.mode' => 'x-accel-redirect', 'file_delivery.accel_prefix' => '/_books/']);

        $response = $this->get($this->fileUrl(), ['Range' => 'bytes=0-3']);

        $response->assertOk();
        $this->assertEquals(
            [
                'x-accel-redirect' => '/_books/Fantasy/Some%20Author/Book%20%231/Part%2001%20%5B100%25%5D.m4b',
                'content-type' => 'audio/mp4',
                'accept-ranges' => 'bytes',
                'body' => '',
            ],
            [
                'x-accel-redirect' => $response->headers->get('X-Accel-Redirect'),
                'content-type' => $response->headers->get('Content-Type'),
                'accept-ranges' => $response->headers->get('Accept-Ranges'),
                'body' => $response->getContent(),
            ],
        );
        $response->assertHeaderMissing('Content-Length');
    }

    public function testXSendfileNamesTheFilesAbsolutePath(): void
    {
        config(['file_delivery.mode' => 'x-sendfile']);

        $response = $this->get($this->fileUrl());

        $response->assertOk();
        $response->assertHeader(
            'X-Sendfile',
            Storage::disk('books')->path($this->directory . '/' . $this->fileName),
        );
        $this->assertEquals('', $response->getContent());
    }

    public function testMissingFilesAreStill404WhateverTheDelivery(): void
    {
        config(['file_delivery.mode' => 'x-accel-redirect']);

        $response = $this->get('/api/v1/books/' . $this->book->id . '/download/missing.m4b');

        $response->assertNotFound();
        $response->assertHeaderMissing('X-Accel-Redirect');
    }

    public function testUnknownModeFallsBackToPhpStreaming(): void
    {
        config(['file_delivery.mode' => 'apache-magic']);

        $response = $this->get($this->fileUrl());

        $response->assertOk();
        $response->assertHeaderMissing('X-Accel-Redirect');
        $this->assertEquals($this->content, $response->streamedContent());
    }
}
