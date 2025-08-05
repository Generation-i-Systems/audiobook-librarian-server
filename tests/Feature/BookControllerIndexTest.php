<?php

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Tests\PersistentDatabaseTestCase as TestCase;
use Mockery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Facade;

class BookControllerIndexTest extends TestCase
{
    // Removed RefreshDatabase to prevent database wipes
    use WithFaker;

    protected $user;
    protected $mockDocumentStoreService;

    protected function setUp(): void
    {
        parent::setUp();

        // Using persistent database instead of in-memory SQLite
        // Database configuration is handled in PersistentDatabaseTestCase

        // Run migrations
        $this->artisan('migrate');

        // Mock the filesystem
        Storage::fake('public');

        // Create a test user
        $this->user = User::create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'role' => 'user',
        ]);

        // Get a fresh instance of the user from the database
        $user = User::find($this->user->id);
        $this->actingAs($user);

        // Mock the Log facade with required methods
        $logMock = \Mockery::mock('log');
        $logMock->shouldReceive('error');
        $logMock->shouldReceive('log');
        $logMock->shouldReceive('info');
        $logMock->shouldReceive('warning');
        $logMock->shouldReceive('debug');
        Log::swap($logMock);

        // Mock the DocumentStoreService
        $this->mockDocumentStoreService = \Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockDocumentStoreService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function testItDisplaysBooksWithArrayAndStringProperties()
    {
        try {
            // Create test data with properly structured arrays for authors and series
            $testBooks = [
                [
                    'id' => 1,
                    'title' => 'Test Book 1',
                    'author' => [
                        ['name' => 'Author 1']
                    ],
                    'series' => [
                        ['seriesName' => 'Test Series 1']
                    ],
                    'cover_image' => 'test1.jpg',
                    'description' => 'Test description 1',
                    'published_date' => '2020-01-01',
                    'publisher' => 'Publisher 1',
                    'isbn' => '1234567890',
                    'language' => 'en',
                    'pages' => 300,
                    'rating' => 4.5,
                    'ratings_count' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 2,
                    'title' => 'Test Book 2',
                    'author' => [
                        ['name' => 'Author 2']
                    ],
                    'series' => [
                        ['seriesName' => 'Test Series 2']
                    ],
                    'cover_image' => 'test2.jpg',
                    'description' => 'Test description 2',
                    'published_date' => '2020-01-02',
                    'publisher' => 'Publisher 2',
                    'isbn' => '0987654321',
                    'language' => 'en',
                    'pages' => 350,
                    'rating' => 4.0,
                    'ratings_count' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            // Mock the DocumentStoreService to return our test data
            $this->mockDocumentStoreService->shouldReceive('listBooks')
                ->once()
                ->with(1, 24, [], true)
                ->andReturn([
                    'data' => $testBooks,
                    'total' => 2,
                    'per_page' => 24,
                    'current_page' => 1,
                    'last_page' => 1,
                    'from' => 1,
                    'to' => 2,
                    'path' => 'http://localhost',
                    'first_page_url' => 'http://localhost?page=1',
                    'last_page_url' => 'http://localhost?page=1',
                    'next_page_url' => null,
                    'prev_page_url' => null,
                ]);

            // Mock the getUniqueValues method for filter options
            $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
                ->with('genre')
                ->andReturn([]);

            $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
                ->with('author')
                ->andReturn([]);

            $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
                ->with('series', 'seriesName')
                ->andReturn([]);

            // Mock the getRecentBooks method with properly structured data
            $recentBooks = [
                [
                    'id' => 3,
                    'title' => 'Recent Book 1',
                    'author' => [
                        ['name' => 'Author 3']
                    ],
                    'series' => [
                        ['seriesName' => 'Test Series 3']
                    ],
                    'cover_image' => 'recent1.jpg',
                    'coverImage' => 'recent1.jpg', // Add coverImage to match what the view expects
                    'description' => 'Recent test description 1',
                    'published_date' => '2023-01-01',
                    'publisher' => 'Publisher 3',
                    'isbn' => '1122334455',
                    'language' => 'en',
                    'pages' => 280,
                    'rating' => 4.8,
                    'ratings_count' => 200,
                    'created_at' => now()->subDays(5), // Recent book
                    'updated_at' => now()->subDays(5),
                ]
            ];
            
            $this->mockDocumentStoreService->shouldReceive('getRecentBooks')
                ->with(10, 30)
                ->andReturn($recentBooks);

            // Make the request to the index page
            $response = $this->get(route('books.index'));

            // If we get here, the request didn't throw an exception
            dump('Response status: ' . $response->status());
            dump('Response content: ' . $response->content());

            // If we got a non-200 response, dump the exception that would have been thrown
            if ($response->status() !== 200) {
                $exception = $response->exception;
                if ($exception) {
                    dump('Exception class: ' . get_class($exception));
                    dump('Exception message: ' . $exception->getMessage());
                    dump('Exception trace: ' . $exception->getTraceAsString());
                }
            }

            // Get the response content before making assertions
            $content = $response->getContent();
            
            // If we get a 500 error, dump the full response content for debugging
            if ($response->status() === 500) {
                dump('Response status: 500');
                dump('Response content:');
                dump($content);
                
                // Get the actual exception if available
                $exception = $response->exception ?? null;
                
                if ($exception) {
                    dump('Exception class: ' . get_class($exception));
                    dump('Exception message: ' . $exception->getMessage());
                    dump('Exception file: ' . $exception->getFile() . ':' . $exception->getLine());
                    dump('Exception trace: ' . $exception->getTraceAsString());
                    
                    // If there's a previous exception, show that too
                    $previous = $exception->getPrevious();
                    while ($previous) {
                        dump('Previous exception:');
                        dump('  Class: ' . get_class($previous));
                        dump('  Message: ' . $previous->getMessage());
                        dump('  File: ' . $previous->getFile() . ':' . $previous->getLine());
                        $previous = $previous->getPrevious();
                    }
                } else {
                    // Try to get the exception from the container if not in the response
                    try {
                        $exception = app('Illuminate\Contracts\Debug\ExceptionHandler');
                        if (method_exists($exception, 'getException')) {
                            $exception = $exception->getException();
                            if ($exception) {
                                dump('Exception from handler: ' . get_class($exception));
                                dump('Message: ' . $exception->getMessage());
                            }
                        }
                    } catch (\Exception $e) {
                        dump('Could not get exception from handler: ' . $e->getMessage());
                    }
                }
                
                // Also check the session for errors
                if (session()->has('errors')) {
                    dump('Validation errors:', session('errors')->all());
                }
                
                // Dump the full response headers
                dump('Response headers:', $response->headers->all());
                
                // If we have a JSON response, try to decode it
                if (str_contains($response->headers->get('Content-Type'), 'application/json')) {
                    $json = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        dump('JSON response:', $json);
                    }
                }
                
                // Dump the last few lines of the Laravel log
                try {
                    $logPath = storage_path('logs/laravel.log');
                    if (file_exists($logPath)) {
                        $logContent = file_get_contents($logPath);
                        $logLines = explode("\n", $logContent);
                        $lastLines = array_slice($logLines, -20); // Last 20 lines
                        dump('Last log entries:', $lastLines);
                    }
                } catch (\Exception $e) {
                    dump('Could not read log file: ' . $e->getMessage());
                }
            }
            
            // Now assert the response status
            $response->assertStatus(200);

            // If we get here, the status is 200, now check view data
            $view = $response->original;
            dump('View data keys: ' . implode(', ', array_keys($view->getData())));

            // Check if books data is present in the view
            if (!isset($view->getData()['books'])) {
                dump('Books data is missing from the view');
            } else {
                dump('Books data found in view');
            }

            // Check if recentBooks data is present in the view
            if (!isset($view->getData()['recentBooks'])) {
                dump('Recent books data is missing from the view');
            } else {
                dump('Recent books data found in view');
            }

            // Assert the view has the books data
            $response->assertViewHas('books');
            $response->assertViewHas('recentBooks');

            // Assert the view contains the book titles (handling both string and array cases)
            $response->assertSee('Test Book 1');
            $response->assertSee('Test Book 2');

            // Assert the view contains the series names
            $response->assertSee('Test Series 1');
            $response->assertSee('Test Series 2');
        } catch (\Exception $e) {
            // Catch any exceptions and dump detailed information
            dump('Exception caught in test:');
            dump('Class: ' . get_class($e));
            dump('Message: ' . $e->getMessage());
            dump('File: ' . $e->getFile() . ':' . $e->getLine());
            dump('Trace: ' . $e->getTraceAsString());

            // Re-throw the exception to fail the test
            throw $e;
        }
    }
}
