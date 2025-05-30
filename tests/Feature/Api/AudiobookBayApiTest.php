<?php

namespace Tests\Feature\Api;

use App\Traits\AudiobookBayApiTrait;
use Illuminate\Support\Facades\Http;

class AudiobookBayApiTest extends BaseApiTest
{
    private AudiobookBayApiTrait $audiobookBayApi;

    protected string $apiBaseUrl = 'https://audiobookbay.lu';
    protected string $testUsername = 'testuser';
    protected string $testPassword = 'testpass';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a new instance of a class that uses the trait
        $this->audiobookBayApi = new class {
            use AudiobookBayApiTrait;
        };

        // Initialize the API client with test credentials
        $this->audiobookBayApi->initAudiobookBay([
            'username' => $this->testUsername,
            'password' => $this->testPassword,
        ]);
    }

    protected function getServiceName(): string
    {
        return 'audiobookbay';
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'items' => [
                [
                    'title' => 'Test Audiobook',
                    'authors' => [['name' => 'Test Author']],
                    'description' => 'Test Description',
                    'cover_image_url' => 'http://example.com/cover.jpg',
                    'metadata' => [
                        'source' => 'audiobookbay',
                        'categories' => ['Fiction']
                    ]
                ]
            ]
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'title' => 'Test Audiobook',
            'authors' => [['name' => 'Test Author']],
            'narrators' => [['name' => 'Test Narrator']],
            'description' => 'Test Description',
            'cover_image_url' => 'http://example.com/cover.jpg',
            'metadata' => [
                'source' => 'audiobookbay',
                'categories' => ['Fiction'],
                'format' => 'MP3',
                'size' => '100 MB',
                'bitrate' => '128 kbps',
                'downloads' => [
                    [
                        'url' => 'https://audiobookbay.lu/download/test',
                        'text' => 'Download'
                    ]
                ]
            ]
        ];
    }



    /** @test */
    public function it_can_login()
    {
        // Mock successful login
        Http::fake([
            'audiobookbay.lu/member/login.php' => Http::response('', 200, ['Set-Cookie' => 'test_cookie=value']),
        ]);

        $result = $this->login();
        
        $this->assertTrue($result);
    }

    /** @test */
    public function it_can_search_books()
    {
        $this->mockSuccessfulSearchResponse();
        
        $results = $this->searchBooks($this->testQuery);
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertCommonBookStructure($results[0]);
    }

    /** @test */
    public function it_can_get_book_details()
    {
        $this->mockSuccessfulDetailsResponse();
        
        $book = $this->getBookDetails('test-id');
        
        $this->assertIsArray($book);
        $this->assertCommonBookStructure($book);
    }
}
