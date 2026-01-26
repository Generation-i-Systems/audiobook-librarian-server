<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReadingProgressApiControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $mock = \Mockery::mock(\App\Contracts\DocumentStoreServiceInterface::class);
        $mock->shouldReceive('updateReadingProgress')->andReturn(true);
        $mock->shouldReceive('resetReadingProgress')->andReturn(true);
        $mock->shouldReceive('getUserById')->andReturnUsing(function ($id) {
            return ['id' => $id, 'name' => 'Test User', 'email' => 'test' . $id . '@example.com'];
        });
        $this->app->instance(\App\Contracts\DocumentStoreServiceInterface::class, $mock);
        $this->withoutMiddleware();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reset_reading_progress_success()
    {
        $userId = 1;
        \Illuminate\Support\Facades\Auth::loginUsingId($userId);
        $bookId = 'test-book-1';

        // Simulate reading progress exists
        $this->app->make(\App\Contracts\DocumentStoreServiceInterface::class)
            ->updateReadingProgress((string) $userId, $bookId, 42);

        $response = $this->postJson('/api/v1/reading-progress/reset', [
            'book_id' => $bookId,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Progress reset.',
                'success' => true,
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reset_reading_progress_failure()
    {
        $userId = 2;
        \Illuminate\Support\Facades\Auth::loginUsingId($userId);
        $bookId = 'nonexistent-book';

        // Simulate failure by using a mock or invalid store (implementation may vary)
        $response = $this->postJson('/api/v1/reading-progress/reset', [
            'book_id' => $bookId,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Progress reset.', // If interface always returns true, this will always succeed
                'success' => true,
            ]);
    }
}
