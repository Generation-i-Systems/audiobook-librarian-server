<?php
// File intentionally left blank. Trait-based feature tests removed due to service refactor.

namespace Tests\Feature\Api;

use App\Traits\BaseApiTrait;
use App\Traits\GoogleBooksApiTrait;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

class GoogleBooksApiTest extends BaseApiTest
{
    private object $googleBooksApi;

    protected string $serviceBaseUrl = 'https://www.googleapis.com/books/v1'; // Actual base for the service
    protected string $volumesEndpointPath = '/volumes'; // Common endpoint path

    protected function setUp(): void
    {
        parent::setUp();

        // Create a new instance of a class that uses the trait
        $this->googleBooksApi = new class {
            use BaseApiTrait;
            use GoogleBooksApiTrait;
        };

        // Initialize the API client with test credentials
        $this->googleBooksApi->initGoogleBooks([
            'api_key' => $this->apiKey, // Use the apiKey from BaseApiTest
            'base_url' => $this->serviceBaseUrl // Use the corrected serviceBaseUrl
        ]);
    }

    protected function getServiceName(): string
    {
        return 'google_books';
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'items' => [
                [
                    'id' => 'test_id',
                    'volumeInfo' => [
                        'title' => 'Test Book',
                        'authors' => ['Test Author'],
                        'description' => 'Test Description',
                        'imageLinks' => [
                            'thumbnail' => 'http://example.com/cover.jpg',
                        ],
                        'publishedDate' => '2023-01-01',
                        'publisher' => 'Test Publisher',
                        'categories' => ['Fiction'],
                    ],
                ],
            ],
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'id' => 'test_id',
            'volumeInfo' => [
                'title' => 'Test Book',
                'authors' => ['Test Author'],
                'description' => 'Test Description',
                'imageLinks' => [
                    'thumbnail' => 'http://example.com/cover.jpg',
                ],
                'publishedDate' => '2023-01-01',
                'publisher' => 'Test Publisher',
                'categories' => ['Fiction'],
            ],
        ];
    }

    #[Test]
    public function testSearchBooks(): void
    {
        $expectedSearchUrl = rtrim($this->serviceBaseUrl, '/') . $this->volumesEndpointPath;
        $mockSearchResponse = $this->getMockSearchResponse();

        Http::fake(function (Request $request) use ($expectedSearchUrl, $mockSearchResponse) {
            try {
                $requestData = $request->data();
                $urlParts = parse_url($request->url());
                $scheme = $urlParts['scheme'] ?? 'http';
                $host = $urlParts['host'] ?? '';
                $path = $urlParts['path'] ?? '';
                $urlWithoutQuery = $scheme . '://' . $host . $path;

                // Google Books API search uses the base /volumes endpoint
                $urlCondition = rtrim($urlWithoutQuery, '/') === $expectedSearchUrl;
                $queryParamCondition = isset($requestData['q']);

                if ($urlCondition && $queryParamCondition) {
                    return Http::response($mockSearchResponse, 200);
                }

                Log::warning(
                    'GBooksTest: No search mock match for URL: ' . $request->url()
                );
                return Http::response(['error' => 'Mock not found for search'], 404);
            } catch (\Throwable $e) {
                Log::error('GBooksTest: Search mock exception: ' . $e->getMessage());
                return Http::response(['error' => 'Exception in mock callback'], 500);
            }
        });

        $results = $this->googleBooksApi->searchBooks('test query');

        $this->assertIsArray($results);
        $this->assertArrayHasKey('items', $results);
        $this->assertCount(1, $results['items']);
        $this->assertEquals('test_id', $results['items'][0]['id']);
    }

    #[Test]
    public function testGetBookDetails(): void
    {
        $expectedVolumeId = 'test_id'; // Corresponds to getBookDetails('test_id')
        $expectedDetailsUrl = rtrim($this->serviceBaseUrl, '/') . $this->volumesEndpointPath . '/' . $expectedVolumeId;
        $mockDetailsResponse = $this->getMockDetailsResponse();

        $detailsFakeClosure = function (Request $request) use ($expectedDetailsUrl, $mockDetailsResponse) {
            try {
                $requestData = $request->data(); // Capture data for API key check
                $urlParts = parse_url($request->url());
                $scheme = $urlParts['scheme'] ?? 'http';
                $host = $urlParts['host'] ?? '';
                $path = $urlParts['path'] ?? '';
                $urlWithoutQuery = $scheme . '://' . $host . $path;

                $urlCondition = rtrim($urlWithoutQuery, '/') === $expectedDetailsUrl;

                if ($urlCondition) {
                    // Also ensure 'key' parameter is present for details request, as per GoogleBooksApiTrait
                    $apiKeyCondition = isset($requestData['key']);
                    if ($apiKeyCondition) {
                        return Http::response($mockDetailsResponse, 200);
                    }
                    Log::warning(
                        'GBooksTest: Details API key missing for URL: ' . $request->url()
                    );
                }
                Log::warning(
                    'GBooksTest: No details mock match for URL: ' . $request->url()
                );
                return Http::response(['error' => 'Mock not found for details'], 404);
            } catch (\Throwable $e) {
                Log::error('GBooksTest: Details mock exception: ' . $e->getMessage());
                return Http::response(['error' => 'Exception in mock callback'], 500);
            }
        };

        Http::fake($detailsFakeClosure);


        $book = $this->googleBooksApi->getBookDetails('test_id');

        $this->assertIsArray($book);
        $this->assertEquals('test_id', $book['id']);
        $this->assertEquals('Test Book', $book['volumeInfo']['title']);
    }
}
