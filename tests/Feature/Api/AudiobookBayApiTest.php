<?php

namespace Tests\Feature\Api;

use App\Traits\AudiobookBayApiTrait;
use App\Traits\BaseApiTrait; // Re-added BaseApiTrait import
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\BaseApiTest;
use PHPUnit\Framework\Attributes\Test;

class AudiobookBayApiTest extends BaseApiTest
{
    private object $audiobookBayApi; // Changed type hint to object

    protected string $apiBaseUrl = 'https://audiobookbay.lu';
    protected string $testUsername = 'testuser';
    protected string $testPassword = 'testpass';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a new instance of a class that uses the trait
        $this->audiobookBayApi = new class {
            use BaseApiTrait; // Re-added BaseApiTrait
            use AudiobookBayApiTrait {
                AudiobookBayApiTrait::getDefaultHeaders insteadof BaseApiTrait;
            }
        };

        // Initialize the API client with test credentials
        $this->audiobookBayApi->initAudiobookBay([
            'username' => $this->testUsername,
            'password' => $this->testPassword,
        ]);
        $this->audiobookBayApi->setBaseUrl($this->apiBaseUrl); // Explicitly set base URL
        $this->audiobookBayApi->setServiceName($this->getServiceName()); // Set service name
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
                        'categories' => ['Fiction'],
                    ],
                ],
            ],
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
                        'text' => 'Download',
                    ],
                ],
            ],
        ];
    }



    #[Test]
    public function testCanLogin()
    {
        // Mock successful login
        Http::fake([
            'audiobookbay.lu/member/login.php' => Http::response('', 200, ['Set-Cookie' => 'test_cookie=value']),
        ]);

        $result = $this->audiobookBayApi->login();

        $this->assertTrue($result);
    }

    #[Test]
    public function testCanSearchBooks()
    {
        $this->mockSuccessfulSearchResponse();

        $results = $this->audiobookBayApi->searchBooks($this->testQuery);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertCommonBookStructure($results['items'][0]);
    }

    #[Test]
    public function testCanGetBookDetails()
    {
        $this->mockSuccessfulDetailsResponse();

        $book = $this->audiobookBayApi->getBookDetails('test-id');

        $this->assertIsArray($book);
        $this->assertCommonBookStructure($book);
    }
}
