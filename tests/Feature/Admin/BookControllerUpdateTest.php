<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Services\MongoService;
use Mockery;

class BookControllerUpdateTest extends TestCase
{
    // @test
    #[\PHPUnit\Framework\Attributes\Test]
    public function update_book_successfully_updates_and_redirects()
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->admin()->create();
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

        $mock = Mockery::mock(MongoService::class);
        $mock->shouldReceive('getBook')->with($bookId)->andReturn($bookData);
        $mock->shouldReceive('updateBook')->with($bookId, Mockery::on(function($data) {
            return $data['title'] === 'New Title' && $data['author'][0] === 'New Author' && $data['genre'][0] === 'New Genre';
        }))->once();
        $this->app->instance(MongoService::class, $mock);

        $response = $this->patch(route('admin.books.update', $bookId), [
            'title' => 'New Title',
            'author' => ['New Author'],
            'genre' => ['New Genre'],
            'publishedYear' => 2021,
            'description' => 'New desc',
            'directoryPath' => 'new/path',
        ]);
        $response->assertRedirect(route('admin.books.edit', $bookId));
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
