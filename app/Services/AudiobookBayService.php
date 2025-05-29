<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use App\Traits\AudiobookBayApiTrait;
use App\Traits\AudiobookBayParserTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class AudiobookBayService extends BaseBookService implements BookServiceInterface
{
    use AudiobookBayApiTrait, AudiobookBayParserTrait {
        search as protected audiobookBaySearch;
        getBookDetails as protected audiobookBayGetBookDetails;
    }

    protected string $baseUrl = 'https://audiobookbay.lu';
    protected int $defaultLimit = 10;
    protected int $cacheTtl = 86400; // 24 hours in seconds
    protected ?string $cookie = null;

    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return 'audiobookbay';
    }

    /**
     * @inheritDoc
     */
    /**
     * @inheritDoc
     */
    protected function performSearch(string $query, array $options = []): ?array
    {
        $limit = $options['limit'] ?? $this->defaultLimit;
        $page = $options['page'] ?? 1;
        
        $cacheKey = 'audiobookbay_search_' . md5($query . '_' . $limit . '_' . $page);
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($query, $limit) {
            try {
                $html = $this->audiobookBaySearch($query);
                if (!$html) {
                    Log::warning('No HTML content returned from AudiobookBay search', ['query' => $query]);
                    return [];
                }
                
                $results = $this->parseSearchResults($html);
                
                // Format results to match expected structure
                $formattedResults = [];
                foreach ($results as $result) {
                    $formattedResults[] = [
                        'title' => $result['title'] ?? '',
                        'author' => $result['authors'][0]['name'] ?? '',
                        'narrator' => $result['narrators'][0]['name'] ?? '',
                        'size' => $result['metadata']['size'] ?? '',
                        'format' => $result['metadata']['format'] ?? '',
                        'link' => $result['url'] ?? '',
                        'cover' => $result['cover_image_url'] ?? '',
                        'description' => $result['description'] ?? '',
                        'metadata' => $result['metadata'] ?? []
                    ];
                }
                
                // Limit results
                if ($limit > 0) {
                    $formattedResults = array_slice($formattedResults, 0, $limit);
                }
                
                return $formattedResults;
            } catch (\Exception $e) {
                Log::error('Error searching AudiobookBay', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return [];
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function getBookDetails(string $id): ?array
    {
        return $this->performGetBookDetails($id);
    }
    
    /**
     * @inheritDoc
     */
    protected function performGetBookDetails(string $id): ?array
    {
        $cacheKey = 'audiobookbay_book_' . md5($id);
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($id) {
            try {
                // First try to search for the book
                $results = $this->performSearch($id, ['limit' => 1]);
                
                if (empty($results)) {
                    Log::warning('No results found for book details', ['id' => $id]);
                    return null;
                }
                
                // Get the first result's URL
                $bookUrl = $results[0]['url'] ?? null;
                if (!$bookUrl) {
                    Log::warning('No URL found in search results', ['id' => $id]);
                    return null;
                }
                
                // Fetch the book details page
                $html = $this->makeRequest($bookUrl);
                if (!$html) {
                    Log::warning('Failed to fetch book details page', ['url' => $bookUrl]);
                    return null;
                }
                
                // Parse the book details
                $details = $this->parseAudiobookDetails($html);
                
                return [
                    'title' => $details['title'] ?? '',
                    'authors' => $details['authors'] ?? [],
                    'narrators' => $details['narrators'] ?? [],
                    'description' => $details['description'] ?? '',
                    'cover_image' => $details['cover_image_url'] ?? '',
                    'published_date' => $details['published_date'] ?? null,
                    'metadata' => $details['metadata'] ?? []
                ];
            } catch (\Exception $e) {
                Log::error('Error getting book details from AudiobookBay', [
                    'id' => $id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return null;
            }
        });
    }

    /**
     * Get the AudiobookBay username from config
     */
    protected function getAudiobookBayUsername(): ?string
    {
        return Config::get('services.audiobookbay.username');
    }
    
    /**
     * Get the AudiobookBay password from config
     */
    protected function getAudiobookBayPassword(): ?string
    {
        return Config::get('services.audiobookbay.password');
    }
    
    /**
     * Make an HTTP request to AudiobookBay
     * 
     * @param string $url
     * @param string $method
     * @param array $data
     * @return string|null
     */
    protected function makeRequest(string $url, string $method = 'GET', array $data = []): ?string
    {
        try {
            $client = new \GuzzleHttp\Client([
                'cookies' => true,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ]
            ]);
            
            $options = [
                'http_errors' => false,
                'timeout' => 30,
            ];
            
            if ($method === 'POST') {
                $options['form_params'] = $data;
            }
            
            $response = $client->request($method, $url, $options);
            
            if ($response->getStatusCode() !== 200) {
                Log::warning('Failed to fetch URL', [
                    'url' => $url,
                    'status' => $response->getStatusCode(),
                ]);
                return null;
            }
            
            return (string) $response->getBody();
        } catch (\Exception $e) {
            Log::error('Request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a string is a URL
     */
    protected function isUrl(string $string): bool
    {
        return filter_var($string, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Parse search results from HTML
     */
    protected function parseSearchResults(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new \DOMXPath($dom);
        
        $results = [];
        $entries = $xpath->query('//div[contains(@class, "post")]');
        
        foreach ($entries as $entry) {
            $result = [
                'title' => '',
                'url' => '',
                'cover' => '',
                'author' => '',
                'narrator' => '',
                'size' => '',
                'format' => '',
                'bitrate' => '',
            ];
            
            // Title and URL
            $titleNode = $xpath->query('.//div[contains(@class, "postTitle")]//a', $entry)->item(0);
            if ($titleNode) {
                $result['title'] = trim($titleNode->textContent);
                $result['url'] = $this->baseUrl . $titleNode->getAttribute('href');
            }
            
            // Cover image
            $imgNode = $xpath->query('.//div[contains(@class, "postImg")]//img', $entry)->item(0);
            if ($imgNode) {
                $result['cover'] = $imgNode->getAttribute('src');
            }
            
            // Details (author, narrator, size, format)
            $details = $xpath->query('.//div[contains(@class, "postInfo")]', $entry);
            if ($details->length > 0) {
                $text = $details->item(0)->textContent;
                
                // Extract author
                if (preg_match('/Author:\s*(.*?)(?:\n|$)/i', $text, $matches)) {
                    $result['author'] = trim($matches[1]);
                }
                
                // Extract narrator
                if (preg_match('/Narrated by:\s*(.*?)(?:\n|$)/i', $text, $matches)) {
                    $result['narrator'] = trim($matches[1]);
                }
                
                // Extract size
                if (preg_match('/Size:\s*(\d+(\.\d+)?\s*[KMG]B)/i', $text, $matches)) {
                    $result['size'] = trim($matches[1]);
                }
                
                // Extract format
                if (preg_match('/Format:\s*(.*?)(?:\n|$)/i', $text, $matches)) {
                    $result['format'] = trim($matches[1]);
                }
                
                // Extract bitrate if available
                if (preg_match('/(\d+\s*kbps)/i', $text, $matches)) {
                    $result['bitrate'] = $matches[1];
                }
            }
            
            if (!empty($result['title'])) {
                $results[] = $result;
            }
        }
        
        return $results;
    }

    /**
     * Format search results into a consistent structure
     * 
     * @deprecated Use the direct formatting in performSearch instead
     */
    protected function formatSearchResults(array $results): array
    {
        return array_map(function ($result) {
            return [
                'title' => $result['title'] ?? '',
                'author' => $result['author'] ?? '',
                'narrator' => $result['narrator'] ?? '',
                'description' => $result['description'] ?? '',
                'cover_image' => $result['cover_image_url'] ?? $result['cover_image'] ?? '',
                'date_published' => $result['published_date'] ?? $result['date_published'] ?? null,
                'metadata' => $result['metadata'] ?? [],
            ];
        }, $results);
    }

    /**
     * Format book details to a consistent format
     */
    protected function formatBookDetails(array $details): array
    {
        return [
            'id' => $details['url'] ?? null,
            'title' => $details['title'] ?? 'Unknown Title',
            'authors' => $this->formatAuthors($details['author'] ?? ''),
            'narrators' => $this->formatNarrators($details['narrator'] ?? ''),
            'description' => $details['description'] ?? null,
            'published_date' => $details['datePublished'] ?? null,
            'cover_image_url' => $details['cover_image'] ?? null,
            'categories' => $this->formatCategories($details['category'] ?? []),
            'keywords' => $details['keywords'] ?? [],
            'series' => $details['series'] ?? null,
            'series_number' => $details['seriesNumber'] ?? null,
            'metadata' => [
                'source' => 'AudiobookBay',
                'url' => $details['url'] ?? null,
            ]
        ];
    }

    /**
     * Format authors string to a consistent format
     */
    protected function formatAuthors(string $authors): array
    {
        if (empty($authors)) {
            return [];
        }
        
        return array_map(function ($author) {
            return [
                'author' => [
                    'name' => trim($author),
                    'id' => null,
                ]
            ];
        }, explode(',', $authors));
    }

    /**
     * Format narrators string to a consistent format
     */
    protected function formatNarrators(?string $narrator): array
    {
        if (empty($narrator)) {
            return [];
        }
        
        return array_map(function ($narrator) {
            return [
                'author' => [
                    'name' => trim($narrator),
                    'id' => null,
                ]
            ];
        }, explode(',', $narrator));
    }

    /**
     * Format categories array to a consistent format
     */
    protected function formatCategories(array $categories): array
    {
        return array_map(function ($category) {
            return [
                'genre' => [
                    'name' => $category,
                ]
            ];
        }, $categories);
    }

    /**
     * Check if the service is available
     */
    public function isAvailable(): bool
    {
        return !empty($this->getAudiobookBayUsername()) && !empty($this->getAudiobookBayPassword());
    }
}
