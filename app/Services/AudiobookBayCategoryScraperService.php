<?php

namespace App\Services;

use App\Models\DiscoveredBook;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AudiobookBayCategoryScraperService
{
    protected AudiobookBayApiService $apiService;

    protected AudiobookBayService $audiobookBayService;

    /**
     * Map of category slugs to relative paths.
     *
     * @var array<string,string>
     */
    protected array $categories = [
        'sci-fi' => 'audio-books/sci-fi',
        'fantasy' => 'audio-books/fantasy',
        'litrpg' => 'audio-books/litrpg',
    ];

    public function __construct(
        AudiobookBayApiService $apiService,
        AudiobookBayService $audiobookBayService
    ) {
        $this->apiService = $apiService;
        $this->audiobookBayService = $audiobookBayService;
    }

    public function scrapeCategoryUntilLastSeen(string $category, ?string $lastSeenAbbId = null): array
    {
        $path = $this->categories[$category] ?? null;
        $categoryUrl = $path ? $this->audiobookBayService->buildCategoryUrl($path) : null;

        if (!$categoryUrl) {
            Log::error('Invalid ABB category', ['category' => $category]);

            return [];
        }

        Log::info('[ABB-CATEGORY] Starting category scrape', [
            'category' => $category,
            'url' => $categoryUrl,
            'lastSeenAbbId' => $lastSeenAbbId,
        ]);

        $newBooks = [];
        $page = 1;
        $foundLastSeen = false;

        while (!$foundLastSeen && $page <= 50) {
            $pageUrl = $page > 1 ? rtrim($categoryUrl, '/') . "/page/$page/" : $categoryUrl;

            Log::info('[ABB-CATEGORY] Scraping page', ['page' => $page, 'url' => $pageUrl]);

            $books = $this->scrapeCategoryPage($pageUrl, $category);

            if (empty($books)) {
                Log::info('[ABB-CATEGORY] No books found on page, stopping', ['page' => $page]);
                break;
            }

            foreach ($books as $book) {
                if ($lastSeenAbbId && $book['abb_id'] === $lastSeenAbbId) {
                    Log::info('[ABB-CATEGORY] Found last seen book', ['abb_id' => $lastSeenAbbId]);
                    $foundLastSeen = true;
                    break;
                }

                $newBooks[] = $book;
            }

            $page++;
            usleep(500000); // 0.5 second delay between pages
        }

        Log::info('[ABB-CATEGORY] Category scrape completed', [
            'category' => $category,
            'pages' => $page - 1,
            'newBooks' => count($newBooks),
        ]);

        return $newBooks;
    }

    protected function scrapeCategoryPage(string $url, string $category): array
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning('[ABB-CATEGORY] Failed to fetch page', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $html = $response->body();
            $dom = new DOMDocument();
            @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);

            $books = [];
            $postDivs = $xpath->query('//div[contains(@class, "post")]');

            foreach ($postDivs as $postDiv) {
                $book = $this->parseBookFromPost($postDiv, $xpath, $category);
                if ($book) {
                    $books[] = $book;
                }
            }

            return $books;
        } catch (\Exception $e) {
            Log::error('[ABB-CATEGORY] Error scraping page', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function parseBookFromPost($postDiv, DOMXPath $xpath, string $category): ?array
    {
        try {
            $titleNode = $xpath->query('.//div[@class="postTitle"]/h2/a', $postDiv)->item(0);
            if (!$titleNode) {
                return null;
            }

            $url = $titleNode->getAttribute('href');
            $abbId = basename(parse_url($url, PHP_URL_PATH) ?? '');

            if (!$abbId) {
                return null;
            }

            $title = trim($titleNode->textContent);

            $contentDiv = $xpath->query('.//div[@class="postContent"]', $postDiv)->item(0);
            $author = null;
            $narrator = null;

            if ($contentDiv) {
                foreach ($xpath->query('.//div', $contentDiv) as $div) {
                    $text = $div->textContent;
                    if (str_contains($text, 'Written by:')) {
                        $author = trim(str_replace('Written by:', '', $text));
                    }
                    if (str_contains($text, 'Narrated by:')) {
                        $narrator = trim(str_replace('Narrated by:', '', $text));
                    }
                }
            }

            $coverUrl = null;
            $imgNode = $xpath->query('.//img[@class="wp-post-image"]', $postDiv)->item(0);
            if ($imgNode) {
                $src = $imgNode->getAttribute('src');
                if (!str_contains($src, '/images/search.gif')) {
                    $coverUrl = $src;
                }
            }

            return [
                'abb_id' => $abbId,
                'title' => $title,
                'author' => $author,
                'narrator' => $narrator,
                'category' => $category,
                'url' => $url,
                'cover_url' => $coverUrl,
                'discovered_at' => now(),
            ];
        } catch (\Exception $e) {
            Log::warning('[ABB-CATEGORY] Error parsing book from post', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function enrichBookWithDetails(array $book): array
    {
        try {
            Log::debug('[ABB-CATEGORY] Enriching book with details', ['abb_id' => $book['abb_id']]);

            $details = $this->apiService->getAudiobookDetails($book['abb_id']);

            if ($details && is_array($details)) {
                return array_merge($book, [
                    'description' => $details['description'] ?? $book['description'] ?? null,
                    'cover_url' => $details['coverImageUrl'] ?? $details['cover_image_url'] ?? $book['cover_url'],
                    'metadata' => $details['metadata'] ?? [],
                ]);
            }

            return $book;
        } catch (\Exception $e) {
            Log::warning('[ABB-CATEGORY] Failed to enrich book', [
                'abb_id' => $book['abb_id'],
                'error' => $e->getMessage(),
            ]);

            return $book;
        }
    }

    public function saveDiscoveredBook(array $bookData): ?DiscoveredBook
    {
        try {
            return DiscoveredBook::updateOrCreate(
                ['abb_id' => $bookData['abb_id']],
                $bookData
            );
        } catch (\Exception $e) {
            Log::error('[ABB-CATEGORY] Failed to save discovered book', [
                'abb_id' => $bookData['abb_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getLastSeenBookForCategory(string $category): ?string
    {
        $lastBook = DiscoveredBook::byCategory($category)
            ->orderBy('discovered_at', 'desc')
            ->first();

        return $lastBook?->abb_id;
    }

    public function getCategories(): array
    {
        return array_keys($this->categories);
    }
}
