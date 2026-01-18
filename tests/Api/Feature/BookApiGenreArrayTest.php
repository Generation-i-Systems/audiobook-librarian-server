<?php

namespace Tests\Api\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class BookApiGenreArrayTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test that genre is always returned as an array, even when stored as a string
     */
    public function test_genre_is_always_returned_as_array()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        // Mock the DocumentStoreServiceInterface
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            // Mock response for a book with genre as a string
            $mock->shouldReceive('getBook')
                ->once()
                ->with('1', \Mockery::any())
                ->andReturn([
                    'id' => 1,
                    'title' => 'Test Book 1',
                    'author' => ['John Doe'],
                    'narrator' => ['Jane Smith'],
                    'genre' => 'Science Fiction', // String genre
                    'year' => 2023,
                    'duration' => '10:30:00',
                    'description' => 'A test book description',
                    'cover_image' => 'test/cover.jpg',
                    'file_count' => 1,
                    'total_size' => 1000,
                    'created_at' => '2023-01-01T00:00:00Z',
                    'updated_at' => '2023-01-01T00:00:00Z',
                ]);

            // Mock response for a book with genre as an array
            $mock->shouldReceive('getBook')
                ->once()
                ->with('2', \Mockery::any())
                ->andReturn([
                    'id' => 2,
                    'title' => 'Test Book 2',
                    'author' => ['Jane Doe'],
                    'narrator' => ['John Smith'],
                    'genre' => ['Fantasy', 'Adventure'], // Array genre
                    'year' => 2023,
                    'duration' => '08:45:00',
                    'description' => 'Another test book description',
                    'cover_image' => 'test/cover2.jpg',
                    'file_count' => 1,
                    'total_size' => 1200,
                    'created_at' => '2023-01-02T00:00:00Z',
                    'updated_at' => '2023-01-02T00:00:00Z',
                ]);
        });

        // Test book with genre as string
        $response = $this->getJson('/api/v1/books/1', ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200);
        $responseData = $response->json();

        // Assert genre is an array
        $this->assertIsArray($responseData['genre']);
        $this->assertCount(1, $responseData['genre']);
        $this->assertEquals('Science Fiction', $responseData['genre'][0]);

        // Test book with genre as array
        $response2 = $this->getJson('/api/v1/books/2', ['X-Acting-As-Test' => '1']);

        $response2->assertStatus(200);
        $responseData2 = $response2->json();

        // Assert genre is an array with multiple values
        $this->assertIsArray($responseData2['genre']);
        $this->assertCount(2, $responseData2['genre']);
        $this->assertContains('Fantasy', $responseData2['genre']);
        $this->assertContains('Adventure', $responseData2['genre']);
    }
}
