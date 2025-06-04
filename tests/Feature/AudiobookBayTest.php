<?php

namespace Tests\Feature;

use App\Traits\AudiobookBayApiTrait;
use App\Traits\AudiobookBayParserTrait;
use App\Traits\BaseApiTrait;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AudiobookBayTest extends TestCase
{
    // use RefreshDatabase;
    // BaseApiTrait is used by AudiobookBayApiTrait, so no need to use it directly here.
    use AudiobookBayApiTrait;
    use AudiobookBayParserTrait;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock environment variables for testing *before* initAudiobookBay
        putenv('AUDIOBOOK_BAY_USERNAME=testuser');
        putenv('AUDIOBOOK_BAY_PASSWORD=testpass');

        $this->initAudiobookBay();

        // Mock the HTTP client for all tests
        // Http::fake(); // This should be commented out if tests use Http::shouldReceive()

        // Clear the rate limiter before each test
        Cache::forget('audiobookbay_rate_limit');

        // Disable logging for tests
        Log::spy();

        // Clear any cached cookies
        Cache::forget('audiobookbay_cookie');
    }

    /** @test */
    public function testCanLoginToAudiobookbay()
    {
        // Create a mock of Illuminate\Http\Client\Response
        $mockedHttpResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);

        // Create a Symfony cookie object
        $symfonyCookie = new \Symfony\Component\HttpFoundation\Cookie('PHPSESSID', 'testsessionidfrommock');

        // Stub methods on the mocked response
        $mockedHttpResponse->shouldReceive('successful')->andReturn(true);
        $mockedHttpResponse->shouldReceive('cookies')->andReturn([$symfonyCookie]); // Return an array containing the cookie
        // If the trait uses ->json() or other methods, they might need stubbing too if they are called before cookies()

        Http::shouldReceive('asForm')->andReturnSelf()
            ->shouldReceive('withHeaders')->withAnyArgs()->andReturnSelf()
            ->shouldReceive('post')
            ->with($this->baseUrl . '/member/login.php', [
                'username' => 'testuser',
                'password' => 'testpass',
                'login' => 'Login',
            ])
            ->andReturn($mockedHttpResponse);

        $cookie = $this->getAuthCookie();

        $this->assertNotEmpty($cookie);
        $this->assertStringContainsString('PHPSESSID=testsessionidfrommock', $cookie);
    }

    /** @test */
    public function testHandlesLoginFailure()
    {
        // Mock failed login response using Http::shouldReceive()
        $mockedFailedHttpResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        $mockedFailedHttpResponse->shouldReceive('successful')->andReturn(true); // Or false, depending on how failure is checked
        $mockedFailedHttpResponse->shouldReceive('cookies')->andReturn([]); // No cookies = login failure for the trait

        Http::shouldReceive('asForm')->once()->andReturnSelf()
            ->shouldReceive('withHeaders')->once()->withAnyArgs()->andReturnSelf()
            ->shouldReceive('post')
            ->once()
            ->with($this->baseUrl . '/member/login.php', [
                'username' => 'testuser',
                'password' => 'testpass',
                'login' => 'Login',
            ])
            ->andReturn($mockedFailedHttpResponse);

        $cookie = $this->getAuthCookie();

        // The trait returns an empty string on failure, not null.
        $this->assertEmpty($cookie);
    }

    /** @test */
    public function testCanSearchAudiobookbay()
    {
        $sampleHtml = file_get_contents(__DIR__ . '/../fixtures/audiobookbay_search.html');

        // Mock successful login response
        $mockedLoginResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        $symfonyCookie = new \Symfony\Component\HttpFoundation\Cookie('PHPSESSID', 'searchsessionid');
        $mockedLoginResponse->shouldReceive('successful')->andReturn(true);
        $mockedLoginResponse->shouldReceive('cookies')->andReturn([$symfonyCookie]);

        // Mock successful search response
        $mockedSearchResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        // --- Mocked Responses --- 
        // Login Response (provides cookie)
        $mockedLoginResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        $symfonyCookie = new \Symfony\Component\HttpFoundation\Cookie('PHPSESSID', 'searchsessionid');
        $mockedLoginResponse->shouldReceive('successful')->andReturn(true);
        $mockedLoginResponse->shouldReceive('cookies')->andReturn([$symfonyCookie]);

        // Search Response (provides HTML content)
        $mockedSearchHtmlResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        $mockedSearchHtmlResponse->shouldReceive('successful')->andReturn(true);
        $mockedSearchHtmlResponse->shouldReceive('body')->andReturn($sampleHtml);
        $mockedSearchHtmlResponse->shouldReceive('cookies')->andReturn([]); // Search response usually doesn't set cookies

        // --- Mocked Pending Requests --- 
        $mockedPendingRequestForLogin = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class);
        $mockedPendingRequestForSearch = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class);

        // --- LOGIN PHASE EXPECTATIONS ---
        Http::shouldReceive('asForm')
            ->once()
            ->ordered('login_phase')
            ->andReturn($mockedPendingRequestForLogin);

        $mockedPendingRequestForLogin->shouldReceive('withHeaders')
            ->once()
            ->ordered('login_phase')
            ->with(['User-Agent' => 'Mozilla/5.0']) // Exact UA used in getAuthCookie's POST
            ->andReturnSelf();

        $mockedPendingRequestForLogin->shouldReceive('post')
            ->once()
            ->ordered('login_phase')
            ->with($this->baseUrl . '/member/login.php', ['username' => 'testuser', 'password' => 'testpass', 'login' => 'Login'])
            ->andReturn($mockedLoginResponse);

        // --- SEARCH PHASE EXPECTATIONS ---
        $expectedSearchUserAgent = 'TestUA';
        $expectedSearchCookie = 'PHPSESSID=searchsessionid';
        $expectedSearchHeaders = [
            'User-Agent' => $expectedSearchUserAgent,
            'Cookie' => $expectedSearchCookie,
            'Accept' => 'application/json', // Added to match actual headers
        ];

        Http::shouldReceive('withHeaders')
            ->once()
            ->with($expectedSearchHeaders)
            ->andReturn($mockedPendingRequestForSearch);

        $expectedSearchParams = [
            's' => 'test',
            'page' => 1,
            'cat' => 'undefined',
            'orderby' => 'relevance',
            'order' => 'desc',
        ];
        $mockedPendingRequestForSearch->shouldReceive('get')
            ->once()
            ->ordered('search_phase')
            ->with('/', [
                's' => 'test', // Corrected to match the actual call searchAudiobooks('test')
                'page' => 1,
                'orderby' => 'relevance',
                'order' => 'desc',
            ])
            ->andReturn($mockedSearchHtmlResponse);

        $results = $this->searchAudiobooks('test');

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        // Example assertion for the first result, if structure is known and consistent from fixture
        if (!empty($results)) {
            $firstResult = $results[0];
            $this->assertArrayHasKey('title', $firstResult);
            $this->assertEquals('Test Book 1', $firstResult['title']);
            $this->assertArrayHasKey('url', $firstResult);
            $this->assertEquals('https://audiobookbay.lu/book/test-book-1', $firstResult['url']);

            $this->assertArrayHasKey('authors', $firstResult);
            $this->assertIsArray($firstResult['authors']);
            $this->assertNotEmpty($firstResult['authors']);
            $this->assertArrayHasKey('name', $firstResult['authors'][0]);
            $this->assertEquals('John Doe', $firstResult['authors'][0]['name']);

            $this->assertArrayHasKey('narrators', $firstResult);
            $this->assertIsArray($firstResult['narrators']);
            $this->assertNotEmpty($firstResult['narrators']);
            $this->assertArrayHasKey('name', $firstResult['narrators'][0]);
            $this->assertEquals('Jane Smith', $firstResult['narrators'][0]['name']);

            $this->assertArrayHasKey('cover_image_url', $firstResult);
            $this->assertEquals('https://example.com/cover1.jpg', $firstResult['cover_image_url']);
            
            $this->assertArrayHasKey('description', $firstResult);
            $this->assertEquals('Description for Test Book 1.', $firstResult['description']);

            $this->assertArrayHasKey('metadata', $firstResult);
            $this->assertArrayHasKey('categories', $firstResult['metadata']);
            $this->assertNotEmpty($firstResult['metadata']['categories']);
            $this->assertEquals('Fiction', $firstResult['metadata']['categories'][0]);

            $this->assertArrayHasKey('language', $firstResult);
            $this->assertEquals('English', $firstResult['language']);
            
            $this->assertArrayHasKey('metadata', $firstResult);
            $this->assertArrayHasKey('format', $firstResult['metadata']);
            $this->assertEquals('MP3', $firstResult['metadata']['format']);

            $this->assertArrayHasKey('metadata', $firstResult);
            $this->assertArrayHasKey('size', $firstResult['metadata']);
            $this->assertEquals('256.7 MB', $firstResult['metadata']['size']);

            $this->assertArrayHasKey('metadata', $firstResult);
            $this->assertArrayHasKey('bitrate', $firstResult['metadata']);
            $this->assertEquals('128 kbps', $firstResult['metadata']['bitrate']);
        }
    }

    /**
     * Parse search results from HTML (copied from the command for testing)
     *
     * @param string $html The HTML content to parse
     * @return array
     */
    protected function parseTestSearchResults(string $html): array
    {
        $results = [];
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Find all book entries
        $entries = $xpath->query('//div[contains(@class, "post")]');

        foreach ($entries as $entry) {
            $result = [
                'title' => '',
                'author' => '',
                'narrator' => '',
                'size' => '',
                'format' => '',
                'link' => '',
                'cover' => '',
            ];

            // Title and link
            $titleNode = $xpath->query('.//h2/a', $entry)->item(0);
            if ($titleNode instanceof \DOMElement) {
                $result['title'] = trim($titleNode->nodeValue);
                $result['link'] = 'https://audiobookbay.lu' . $titleNode->getAttribute('href');
            }

            // Cover image
            $imgNode = $xpath->query('.//div[contains(@class, "postImg")]//img', $entry)->item(0);
            if ($imgNode instanceof \DOMElement) {
                $result['cover'] = $imgNode->getAttribute('src');
            }

            // Details (author, narrator, size, format)
            $details = $xpath->query('.//div[contains(@class, "postInfo")]', $entry);
            if ($details->length > 0) {
                $text = $details->item(0)->nodeValue;

                // Extract author (simplified)
                if (preg_match('/Author:\s*(.+?)(?=\n|$)/i', $text, $matches)) {
                    $result['author'] = trim($matches[1]);
                }

                // Extract narrator (simplified)
                if (preg_match('/Narrated by:\s*(.+?)(?=\n|$)/i', $text, $matches)) {
                    $result['narrator'] = trim($matches[1]);
                }

                // Extract size
                if (preg_match('/Size:\s*(.+?)(?=\n|$)/i', $text, $matches)) {
                    $result['size'] = trim($matches[1]);
                }

                // Extract format
                if (preg_match('/Format:\s*(.+?)(?=\n|$)/i', $text, $matches)) {
                    $result['format'] = trim($matches[1]);
                }
            }

            if (!empty($result['title'])) {
                $results[] = $result;
            }
        }

        return $results;
    }

    // /** @test */
    // public function testCanParseSearchResults()
    // {
    //     $sampleHtml = file_get_contents(__DIR__ . '/../fixtures/audiobookbay_search.html');
    //     $results = $this->parseTestSearchResults($sampleHtml);
    //
    //     $this->assertIsArray($results);
    //     $this->assertNotEmpty($results);
    //
    //     $firstResult = $results[0];
    //     $this->assertArrayHasKey('title', $firstResult);
    //     $this->assertArrayHasKey('author', $firstResult);
    //     $this->assertArrayHasKey('narrator', $firstResult);
    //     $this->assertArrayHasKey('size', $firstResult);
    //     $this->assertArrayHasKey('format', $firstResult);
    //     $this->assertArrayHasKey('link', $firstResult);
    //     $this->assertArrayHasKey('cover', $firstResult);
    // }

    // /** @test */
    // public function testCanParseBookDetails()
    // {
    //     $sampleHtml = file_get_contents(__DIR__ . '/../fixtures/audiobookbay_book.html');
    //     $book = $this->parseAudiobookDetails($sampleHtml);
    //
    //     $this->assertIsArray($book);
    //     $this->assertArrayHasKey('title', $book);
    //     $this->assertArrayHasKey('authors', $book);
    //     $this->assertArrayHasKey('narrators', $book);
    //     $this->assertArrayHasKey('description', $book);
    //     $this->assertArrayHasKey('cover_image_url', $book);
    //     $this->assertArrayHasKey('published_date', $book);
    // }

    // /** @test */
    // public function testRespectsRateLimiting()
    // {
    //     $this->expectException(\RuntimeException::class);
    //     $this->expectExceptionMessage('AudiobookBay API rate limit exceeded.');
    //
    //     // Set up a low rate limit for testing
    //     putenv('AUDIOBOOK_BAY_RATE_LIMIT=1');
    //
    //     // First request should pass
    //     $this->testCanSearchWithRateLimiting();
    //
    //     // Second request should throw exception
    //     $this->testCanSearchWithRateLimiting();
    // }

    // /** @test */
    // public function testCanSearchWithRateLimiting()
    // {
    //     // Mock successful search response
    //     Http::fake([
    //         'audiobookbay.lu/member/login.php' => Http::response('', 200, [
    //             'Set-Cookie' => 'abc=123; path=/;',
    //         ]),
    //         'audiobookbay.lu/?s=test' => Http::response('<html><body>Search Results</body></html>'),
    //     ]);
    //
    //     return $this->searchAudiobooks('test');
    // }
}
