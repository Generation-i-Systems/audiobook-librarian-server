<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;
use App\Models\User;
use App\Services\MongoService;
use Mockery;

class BookControllerUpdateTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookRedirectsToIndexAndSavesCoverCandidate()
    {
        $this->withoutExceptionHandling();
        $user = \App\Models\User::factory()->admin()->create();
        $this->actingAs($user);

        $bookId = 'test-book-1';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old Title',
            'author' => ['Old Author'],
            'genre' => ['Old Genre'],
            'publishedYear' => 2000,
            'description' => 'Old desc',
            'directoryPath' => 'old/path',
        ];

        $mock = \Mockery::mock(\App\Services\MongoService::class);
        $mock->shouldReceive('getBook')->with($bookId)->andReturn($bookData);
        $mock->shouldReceive('updateBook')->with($bookId, \Mockery::on(function ($data) {
            return $data['coverImage'] === 'old/path/coverfile.jpg';
        }))->once();
        $this->app->instance(\App\Services\MongoService::class, $mock);

        $response = $this->patch(route('admin.books.update', $bookId), [
            'title' => 'New Title',
            'author' => ['New Author'],
            'genre' => ['New Genre'],
            'publishedYear' => 2021,
            'description' => 'New desc',
            'directoryPath' => 'old/path',
            'coverImageCandidate' => 'coverfile.jpg',
        ]);
        $response->assertRedirect(route('admin.books.index'));
        $response->assertSessionHas('success', 'Book updated successfully.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookSavesUploadedCoverImage()
    {
        $user = \App\Models\User::factory()->admin()->create();
        $this->actingAs($user);
        $bookId = 'test-book-2';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old Title',
            'author' => ['Old Author'],
            'genre' => ['Old Genre'],
            'publishedYear' => 2000,
            'description' => 'Old desc',
            'directoryPath' => 'old/path',
        ];
        $mock = \Mockery::mock(\App\Services\MongoService::class);
        $mock->shouldReceive('getBook')->with($bookId)->andReturn($bookData);
        $mock->shouldReceive('updateBook')->with($bookId, \Mockery::on(function ($data) {
            return isset($data['coverImage']) && str_starts_with($data['coverImage'], 'old/path/cover_');
        }))->once();
        $this->app->instance(\App\Services\MongoService::class, $mock);
        $file = \Illuminate\Http\UploadedFile::fake()->image('cover.jpg');
        $response = $this->patch(route('admin.books.update', $bookId), [
            'title' => 'New Title',
            'author' => ['New Author'],
            'genre' => ['New Genre'],
            'publishedYear' => 2021,
            'description' => 'New desc',
            'directoryPath' => 'old/path',
            'coverImage' => $file,
        ]);
        $response->assertRedirect(route('admin.books.index'));
        $response->assertSessionHas('success', 'Book updated successfully.');
    }

    // @test
    #[\PHPUnit\Framework\Attributes\Test]
    public function update_book_returns_error_if_not_found()
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $mock = Mockery::mock(MongoService::class);
        $mock->shouldReceive('getBook')->andReturn(null);
        $this->app->instance(MongoService::class, $mock);
        $response = $this->patch(route('admin.books.update', 'missing-book'), [
            'title' => 'T', 'author' => ['A'], 'genre' => ['G']
        ]);
        $response->assertRedirect(route('admin.books.index'));
        $response->assertSessionHasErrors();
    }
}
