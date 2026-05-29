<?php

namespace Tests\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Http\Controllers\Admin\BookController;
use App\Services\AudioFileAnalyzer;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BookControllerUpdateNarratorSeriesTest extends TestCase
{
    use WithoutMiddleware;

    private BookController $controller;
    private $documentStoreService;
    private DocumentstoreUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('info')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);
        Log::shouldReceive('debug')->andReturn(null);

        $this->documentStoreService = $this->createMock(\App\Contracts\DocumentStoreServiceInterface::class);

        $this->controller = new BookController(
            $this->documentStoreService,
            $this->createStub(\App\Services\ExternalCoverService::class),
            new AudioFileAnalyzer()
        );

        $this->user = new DocumentstoreUser([
            'id' => 'test-user-1',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'roles' => ['admin'],
        ]);

        Auth::shouldReceive('user')->andReturn($this->user);
        Auth::shouldReceive('check')->andReturn(true);

        $this->app->bind('request', function () {
            return new Request();
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookPersistsNarratorArray(): void
    {
        $bookId = 'book-1';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old',
            'author' => ['A'],
            'genre' => ['G'],
            'directoryPath' => 'dir/old',
        ];

        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->with($bookId)
            ->willReturn($bookData);

        $this->documentStoreService->method('updateBook')
            ->with(
                $this->equalTo($bookId),
                $this->callback(function ($data) {
                    return isset($data['narrator'])
                        && $data['narrator'] === ['Jane Doe', 'John Roe'];
                })
            )
            ->willReturn(true);

        $request = new Request([
            'title' => 'New',
            'author' => ['A'],
            'genre' => ['G'],
            'narrator' => [' Jane Doe ', '', 'John Roe'],
            'directoryPath' => 'dir/old',
        ]);
        $this->app->instance('request', $request);

        $response = $this->controller->update($request, $bookId);
        $this->assertEquals(302, $response->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookNormalizesSeriesAndCreatesIfMissing(): void
    {
        $bookId = 'book-2';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old',
            'author' => ['A'],
            'genre' => ['G'],
            'directoryPath' => 'dir/old',
        ];

        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->with($bookId)
            ->willReturn($bookData);

        $this->documentStoreService->method('getSeriesByName')
            ->with('Super Powereds')
            ->willReturn(null);
        $this->documentStoreService->method('createSeries')
            ->with('Super Powereds')
            ->willReturn('series-123');

        $this->documentStoreService->method('updateBook')
            ->with(
                $this->equalTo($bookId),
                $this->callback(function ($data) {
                    return isset($data['series'])
                        && is_array($data['series'])
                        && $data['series'][0]['id'] === 'series-123'
                        && $data['series'][0]['seriesName'] === 'Super Powereds'
                        && $data['series'][0]['number'] === '1';
                })
            )
            ->willReturn(true);

        $request = new Request([
            'title' => 'New',
            'author' => ['A'],
            'genre' => ['G'],
            'series' => [['name' => 'Super Powereds', 'number' => '1']],
            'directoryPath' => 'dir/old',
        ]);
        $this->app->instance('request', $request);

        $response = $this->controller->update($request, $bookId);
        $this->assertEquals(302, $response->getStatusCode());
    }
}
