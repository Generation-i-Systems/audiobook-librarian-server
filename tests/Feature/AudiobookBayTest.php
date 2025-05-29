<?php

namespace Tests\Feature;

use App\Traits\AudiobookBayApiTrait;
use App\Traits\AudiobookBayParserTrait;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AudiobookBayTest extends TestCase
{
    use RefreshDatabase;
    use AudiobookBayApiTrait;
    use AudiobookBayParserTrait;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the HTTP client for all tests
        Http::fake();

        // Clear the rate limiter before each test
        Cache::forget('audiobookbay_rate_limit');

        // Disable logging for tests
        Log::spy();

        // Mock environment variables for testing
        putenv('AUDIOBOOK_BAY_USERNAME=testuser');
        putenv('AUDIOBOOK_BAY_PASSWORD=testpass');

        // Clear any cached cookies
        Cache::forget('audiobookbay_cookie');
    }

    /** @test */
    public function testCanLoginToAudiobookbay()
    {
        // Mock successful login response
        Http::fake([
            'audiobookbay.lu/member/login.php' => Http::response('', 200, [
                'Set-Cookie' => 'abc=123; path=/;',
            ]),
        ]);

        $cookie = $this->getAudiobookBayCookie();

        $this->assertNotEmpty($cookie);
        $this->assertStringContainsString('abc=123', $cookie);
    }

    /** @test */
    public function testHandlesLoginFailure()
    {
        // Mock failed login response
        Http::fake([
            'audiobookbay.lu/member/login.php' => Http::response('Login failed', 200),
        ]);

        $cookie = $this->getAudiobookBayCookie();

        $this->assertNull($cookie);
    }

    /** @test */
    public function testCanSearchAudiobookbay()
    {
        // Mock successful search response with sample HTML
        $sampleHtml = file_get_contents(__DIR__ . '/../fixtures/audiobookbay_search.html');

        Http::fake([
            'audiobookbay.lu/member/login.php' => Http::response('', 200, [
                'Set-Cookie' => 'abc=123; path=/;',
            ]),
            'audiobookbay.lu/?s=test' => Http::response($sampleHtml, 200),
        ]);

        $html = $this->audiobookBaySearch('test');

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Search Results', $html);
    }

    /**
     * Parse search results from HTML (copied from the command for testing)
     *
     * @param string $html The HTML content to parse
     * @return array
     */
    protected function parseSearchResults(string $html): array
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
                'cover' => ''
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

    /** @test */
    public function testCanParseSearchResults()
    {
        $sampleHtml = file_get_contents(__DIR__ . '/../fixtures/audiobookbay_search.html');
        $results = $this->parseSearchResults($sampleHtml);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        $firstResult = $results[0];
        $this->assertArrayHasKey('title', $firstResult);
        $this->assertArrayHasKey('author', $firstResult);
        $this->assertArrayHasKey('narrator', $firstResult);
        $this->assertArrayHasKey('size', $firstResult);
        $this->assertArrayHasKey('format', $firstResult);
        $this->assertArrayHasKey('link', $firstResult);
        $this->assertArrayHasKey('cover', $firstResult);
    }

    /** @test */
    public function testCanParseBookDetails()
    {
        $sampleHtml = file_get_contents(__DIR__ . '/../fixtures/audiobookbay_book.html');
        $book = $this->parseAudiobookDetails($sampleHtml);

        $this->assertIsArray($book);
        $this->assertArrayHasKey('title', $book);
        $this->assertArrayHasKey('authors', $book);
        $this->assertArrayHasKey('narrators', $book);
        $this->assertArrayHasKey('description', $book);
        $this->assertArrayHasKey('cover_image_url', $book);
        $this->assertArrayHasKey('published_date', $book);
    }

    /** @test */
    public function testRespectsRateLimiting()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AudiobookBay API rate limit exceeded.');

        // Set up a low rate limit for testing
        putenv('AUDIOBOOK_BAY_RATE_LIMIT=1');

        // First request should pass
        $this->testCanSearchWithRateLimiting();

        // Second request should throw exception
        $this->testCanSearchWithRateLimiting();
    }

    protected function testCanSearchWithRateLimiting()
    {
        // Mock successful search response
        Http::fake([
            'audiobookbay.lu/member/login.php' => Http::response('', 200, [
                'Set-Cookie' => 'abc=123; path=/;',
            ]),
            'audiobookbay.lu/?s=test' => Http::response('<html><body>Search Results</body></html>'),
        ]);

        return $this->audiobookBaySearch('test');
    }
}
