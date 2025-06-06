<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Http\Client\Response as IlluminateResponse;

class AudiobookBayApiService
{
    // --- Begin inlined BaseApiTrait properties ---
    protected string $baseUrl = '';
    protected ?string $apiKey = null;
    protected int $cacheTtl = 86400; // 24 hours in seconds
    protected int $rateLimit = 100; // Requests per hour
    protected string $serviceName = '';
    // --- End inlined BaseApiTrait properties ---

    // --- Begin inlined BaseParserTrait methods ---
    private static function formatAuthors($authors): array
    {
        if (is_string($authors)) {
            return array_map(function ($name) {
                return [
                    'author' => [
                        'name' => trim($name),
                    ],
                ];
            }, array_filter(explode(',', $authors)));
        }
        if (!is_array($authors)) {
            return [];
        }
        return array_map(function ($author) {
            if (is_string($author)) {
                return [
                    'author' => [
                        'name' => $author,
                    ],
                ];
            }
            return [
                'author' => [
                    'id' => $author['id'] ?? null,
                    'name' => $author['name'] ?? 'Unknown Author',
                    'role' => $author['role'] ?? null,
                ],
            ];
        }, $authors);
    }
    private static function formatNarrators($narrators): array
    {
        if (is_string($narrators)) {
            return array_map(function ($name) {
                return [
                    'narrator' => [
                        'name' => trim($name),
                    ],
                ];
            }, array_filter(explode(',', $narrators)));
        }
        if (!is_array($narrators)) {
            return [];
        }
        return array_map(function ($narrator) {
            if (is_string($narrator)) {
                return [
                    'narrator' => [
                        'name' => $narrator,
                    ],
                ];
            }
            return [
                'narrator' => [
                    'id' => $narrator['id'] ?? null,
                    'name' => $narrator['name'] ?? 'Unknown Narrator',
                ],
            ];
        }, $narrators);
    }
    private static function formatGenres($genres): array
    {
        if (is_string($genres)) {
            return array_map(function ($name) {
                return [
                    'genre' => [
                        'name' => trim($name),
                    ],
                ];
            }, array_filter(explode(',', $genres)));
        }
        if (!is_array($genres)) {
            return [];
        }
        return array_map(function ($genre) {
            if (is_string($genre)) {
                return [
                    'genre' => [
                        'name' => $genre,
                    ],
                ];
            }
            return [
                'genre' => [
                    'id' => $genre['id'] ?? null,
                    'name' => $genre['name'] ?? 'Unknown Genre',
                    'parent_id' => $genre['parent_id'] ?? null,
                ],
            ];
        }, $genres);
    }
    private static function formatSeries($series, $number = null): array
    {
        if (empty($series)) {
            return [];
        }
        if (is_string($series)) {
            return [
                [
                    'series' => [
                        'name' => $series,
                        'number' => $number !== null ? (string) $number : null,
                    ],
                ],
            ];
        }
        if (is_array($series)) {
            if (isset($series['series'])) {
                return [$series];
            }
            if (isset($series[0]) && is_array($series[0])) {
                return array_map(function ($item) {
                    return [
                        'series' => [
                            'id' => $item['id'] ?? null,
                            'name' => $item['name'] ?? 'Unknown Series',
                            'number' => $item['number'] ?? null,
                        ],
                    ];
                }, $series);
            }
            return [
                [
                    'series' => [
                        'id' => $series['id'] ?? null,
                        'name' => $series['name'] ?? 'Unknown Series',
                        'number' => $series['number'] ?? $number,
                    ],
                ],
            ];
        }
        return [];
    }
    private static function formatBookData(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'title' => $data['title'] ?? 'Unknown Title',
            'subtitle' => $data['subtitle'] ?? null,
            'description' => $data['description'] ?? null,
            'published_date' => $data['published_date'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'language' => $data['language'] ?? 'en',
            'isbn' => $data['isbn'] ?? null,
            'isbn13' => $data['isbn13'] ?? $data['isbn_13'] ?? null,
            'asin' => $data['asin'] ?? null,
            'page_count' => $data['page_count'] ?? $data['pages'] ?? null,
            'format' => $data['format'] ?? null,
            'edition' => $data['edition'] ?? null,
            'cover_image_url' => $data['cover_image_url'] ?? $data['cover_url'] ?? $data['image_url'] ?? null,
            'cover_image_thumbnail' => $data['cover_image_thumbnail'] ?? $data['thumbnail_url'] ?? null,
            'authors' => self::formatAuthors($data['authors'] ?? []),
            'narrators' => self::formatNarrators($data['narrators'] ?? []),
            'series' => self::formatSeries($data['series'] ?? null, $data['series_number'] ?? null),
            'genres' => self::formatGenres($data['genres'] ?? $data['categories'] ?? []),
            'rating' => $data['rating'] ?? $data['average_rating'] ?? null,
            'ratings_count' => $data['ratings_count'] ?? $data['ratingsCount'] ?? 0,
            'metadata' => array_merge(
                $data['metadata'] ?? [],
                [
                    'source' => $data['metadata']['source'] ?? 'unknown',
                    'url' => $data['url'] ?? $data['metadata']['url'] ?? null,
                    'created_at' => $data['created_at'] ?? now()->toDateTimeString(),
                    'updated_at' => $data['updated_at'] ?? now()->toDateTimeString(),
                ]
            ),
        ];
    }
    private static function formatSearchResults(array $items): array
    {
        return array_map(function ($item) {
            return self::formatBookData($item);
        }, $items);
    }
    private static function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }
    private static function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return count($parts) > 1 ? end($parts) : '';
    }
    private static function normalizeString(?string $str): string
    {
        if ($str === null) {
            return '';
        }
        return trim(mb_strtolower($str));
    }
    public static function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = self::normalizeString($str1);
        $str2 = self::normalizeString($str2);
        if ($str1 === $str2) {
            return 1.0;
        }
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        if ($len1 < 1 || $len2 < 1) {
            return 0.0;
        }
        $maxLen = max($len1, $len2);
        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $maxLen);
    }
    // --- End inlined BaseParserTrait methods ---

    // --- Begin inlined AudiobookBayParserTrait methods ---
    /**
     * Parse search results from AudiobookBay HTML
     */
    public function parseSearchResults(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        $results = [];
        $items = $xpath->query('//div[contains(@class, "post")]');
        foreach ($items as $item) {
            $result = [
                'title' => '',
                'authors' => [],
                'narrators' => [],
                'description' => '',
                'cover_image_url' => '',
                'url' => '',
                'metadata' => [
                    'source' => 'audiobookbay',
                ],
            ];
            // Extract title and URL
            $titleNode = $xpath->query('.//div[contains(@class, "postTitle")]//a', $item)->item(0);
            if ($titleNode instanceof \DOMElement && $titleNode->hasAttribute('href')) {
                $result['title'] = trim($titleNode->textContent);
                $result['url'] = 'https://audiobookbay.lu' . $titleNode->getAttribute('href');
            }
            // Extract cover image
            $imgNode = $xpath->query('.//div[contains(@class, "postImg")]//img', $item)->item(0);
            if ($imgNode instanceof \DOMElement && $imgNode->hasAttribute('src')) {
                $result['cover_image_url'] = $imgNode->getAttribute('src');
            }
            // Extract author
            $authorNode = $xpath->query('.//div[contains(@class, "postAuthor")]//a', $item)->item(0);
            if ($authorNode instanceof \DOMNode) {
                $result['authors'][] = [
                    'name' => trim($authorNode->textContent),
                ];
            }
            // Extract description
            $descNode = $xpath->query('.//div[contains(@class, "postContent")]', $item)->item(0);
            if ($descNode instanceof \DOMNode) {
                $result['description'] = trim($descNode->textContent);
            }
            // Extract metadata from info section
            $infoNodes = $xpath->query('.//div[contains(@class, "postInfo")]//li', $item);
            foreach ($infoNodes as $infoNode) {
                $text = trim($infoNode->textContent);
                // Extract narrator
                if (Str::startsWith($text, 'Read by:')) {
                    $result['narrators'][] = [
                        'name' => trim(Str::after($text, 'Read by:')),
                    ];
                }
                // Extract categories
                if (Str::startsWith($text, 'Category:')) {
                    $result['metadata']['categories'] = array_map(
                        'trim',
                        explode(',', Str::after($text, 'Category:'))
                    );
                }
                // Extract language
                if (Str::startsWith($text, 'Language:')) {
                    $result['language'] = trim(Str::after($text, 'Language:'));
                }
                // Extract format
                if (Str::startsWith($text, 'Format:')) {
                    $result['metadata']['format'] = trim(Str::after($text, 'Format:'));
                }
                // Extract size
                if (Str::startsWith($text, 'Size:')) {
                    $result['metadata']['size'] = trim(Str::after($text, 'Size:'));
                }
                // Extract bitrate
                if (preg_match('/(\d+\s*kbps)/i', $text, $matches)) {
                    $result['metadata']['bitrate'] = $matches[1];
                }
            }
            if (!empty($result['title'])) {
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * Parse audiobook details from AudiobookBay HTML
     */
    private function parseAudiobookDetails(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        $book = [
            'title' => '',
            'authors' => [],
            'narrators' => [],
            'description' => '',
            'cover_image_url' => '',
            'series' => null,
            'publisher' => null,
            'published_date' => null,
            'categories' => [],
            'metadata' => [],
            'language' => null,
        ];
        // Extract title
        $titleNode = $xpath->query('//div[contains(@class, "book-page-title")]')->item(0);
        if ($titleNode instanceof \DOMNode) {
            $book['title'] = trim($titleNode->textContent);
            // Attempt to extract series from title if present
            if (preg_match('/^(.*?),\s*(?:Book|Vol(?:\.|ume)?)\s*(\d+)(?:\s*-\s*(.*))?$/i', $book['title'], $matches)) {
                $book['series'] = [
                    'name' => trim($matches[1]),
                    'number' => $matches[2],
                ];
                $book['title'] = trim($matches[3] ?? $book['title']);
            }
        }
        // Extract cover image
        $imgNode = $xpath->query('//div[contains(@class, "book-page-cover")]//img')->item(0);
        if ($imgNode instanceof \DOMElement && $imgNode->hasAttribute('src')) {
            $book['cover_image_url'] = $imgNode->getAttribute('src');
        }
        // Extract description
        $descriptionNode = $xpath->query('//div[contains(@class, "book-page-description")]')->item(0);
        if ($descriptionNode instanceof \DOMNode) {
            $book['description'] = trim($descriptionNode->textContent);
        }
        // Extract metadata from info section
        $metadataNodes = $xpath->query('//div[contains(@class, "book-page-meta")]//div[contains(@class, "row")]');
        foreach ($metadataNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $labelNode = $xpath->query('.//div[contains(@class, "label")]', $node)->item(0);
            $valueNode = $xpath->query('.//div[contains(@class, "value")]', $node)->item(0);
            if ($labelNode instanceof \DOMNode && $valueNode instanceof \DOMNode) {
                $label = trim($labelNode->textContent, ": \t\n\r\0\x0B");
                $value = trim($valueNode->textContent);
                switch (strtolower($label)) {
                    case 'author':
                    case 'authors':
                        $book['authors'][] = ['name' => $value];
                        break;
                    case 'narrator':
                    case 'narrators':
                        $book['narrators'][] = ['name' => $value];
                        break;
                    case 'published':
                    case 'published date':
                        if (($timestamp = strtotime($value)) !== false) {
                            $book['published_date'] = date('Y-m-d', $timestamp);
                        }
                        break;
                    case 'publisher':
                        $book['publisher'] = $value;
                        break;
                    case 'category':
                    case 'categories':
                        $book['metadata']['categories'] = array_map('trim', explode(',', $value));
                        break;
                    case 'format':
                        $book['metadata']['format'] = $value;
                        break;
                    case 'size':
                        $book['metadata']['size'] = $value;
                        break;
                    case 'bitrate':
                    case 'bit rate':
                        $book['metadata']['bitrate'] = $value;
                        break;
                    case 'language':
                        $book['language'] = $value;
                        break;
                    default:
                        $book['metadata'][strtolower($label)] = $value;
                }
            }
        }
        // Extract download links
        $downloadNodes = $xpath->query('//div[contains(@class, "download-links")]//a');
        $downloads = [];
        foreach ($downloadNodes as $node) {
            if ($node instanceof \DOMElement && $node->hasAttribute('href')) {
                $downloads[] = [
                    'url' => 'https://audiobookbay.lu' . $node->getAttribute('href'),
                    'text' => trim($node->textContent),
                ];
            }
        }
        if (!empty($downloads)) {
            $book['metadata']['downloads'] = $downloads;
        }
        return $book;
    }
    // --- End inlined AudiobookBayParserTrait methods ---

    // --- Begin inlined BaseApiTrait methods ---
    /**
     * Make an HTTP GET request (inlined from BaseApiTrait)
     */
    protected function httpGetResponse(string $endpoint, array $params = []): ?string
    {
        $cacheKey = $this->getCacheKey($endpoint, $params);
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($endpoint, $params) {
            $this->checkRateLimit();
            $response = Http::withHeaders($this->getDefaultHeaders())
                ->get($this->baseUrl . $endpoint, $params);
            if ($response->successful()) {
                return (string) $response->body(); // Always return a string
            }
            Log::error('API request failed', [
                'service' => $this->serviceName,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            return null;
            // Defensive: ensure closure always returns string|null
        });
    }
    /**
     * Make an HTTP POST request (inlined from BaseApiTrait)
     */
    protected function httpPost(string $endpoint, array $data = []): ?\Illuminate\Http\Client\Response
    {
        $cacheKey = $this->getCacheKey($endpoint, $data);
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($endpoint, $data) {
            $this->checkRateLimit();
            $response = Http::withHeaders($this->getDefaultHeaders())
                ->post($this->baseUrl . $endpoint, $data);
            if ($response->successful()) {
                return $response;
            }
            Log::error('API request failed', [
                'service' => $this->serviceName,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            return null;
        });
    }
    /**
     * Check and enforce rate limiting (inlined from BaseApiTrait)
     */
    protected function checkRateLimit(): void
    {
        $cacheKey = "{$this->serviceName}_rate_limit_" . now()->format('YmdH');
        $count = Cache::get($cacheKey, 0);
        if ($count >= $this->rateLimit) {
            Log::warning('API rate limit reached', ['service' => $this->serviceName]);
            throw new \RuntimeException("{$this->serviceName}API rate limit exceeded. Please try again later.");
        }
        Cache::put($cacheKey, $count + 1, now()->addHour());
    }
    /**
     * Generate a cache key for the request (inlined from BaseApiTrait)
     */
    protected function getCacheKey(string $endpoint, array $params): string
    {
        return "{$this->serviceName}_" . md5($endpoint . json_encode($params));
    }
    /**
     * Set the API key (inlined from BaseApiTrait)
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }
    /**
     * Set the base URL (inlined from BaseApiTrait)
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }
    /**
     * Set the cache TTL in seconds (inlined from BaseApiTrait)
     */
    public function setCacheTtl(int $seconds): self
    {
        $this->cacheTtl = $seconds;
        return $this;
    }
    /**
     * Set the rate limit (requests per hour) (inlined from BaseApiTrait)
     */
    public function setRateLimit(int $requestsPerHour): self
    {
        $this->rateLimit = $requestsPerHour;
        return $this;
    }
    /**
     * Set the service name (inlined from BaseApiTrait)
     */
    public function setServiceName(string $serviceName): self
    {
        $this->serviceName = $serviceName;
        return $this;
    }
    // --- End inlined BaseApiTrait methods ---

    protected ?string $username = null;
    protected ?string $password = null;
    protected ?string $cookie = null;
    // User-Agent for AudiobookBay login and general requests
    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

    public function __construct(array $config = [])
    {
        $this->serviceName = 'AudiobookBay';
        $this->baseUrl = config('services.audiobook_bay.base_url', 'https://audiobookbay.lu'); // Default if not in config

        $this->username = $config['username'] ?? config('services.audiobook_bay.username');
        $this->password = $config['password'] ?? config('services.audiobook_bay.password');
        $this->userAgent = $config['user_agent'] ?? config('services.audiobook_bay.user_agent', $this->userAgent);

        // Initialize from BaseApiTrait or config
        $this->cacheTtl = $config['cache_ttl'] ?? $this->cacheTtl;
        $this->rateLimit = $config['rate_limit'] ?? $this->rateLimit;

        if (empty($this->username) || empty($this->password)) {
            Log::warning('AudiobookBayApiService: Missing username or password. Authentication will likely fail.');
        }
    }

    /**
     * Override getDefaultHeaders from BaseApiTrait to include AudiobookBay specific cookie.
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'User-Agent' => $this->userAgent,
            'Cookie' => $this->getAuthCookie(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8', // Standard browser accept for HTML
        ];
    }

    protected function getAuthCookie(): string
    {
        if ($this->cookie) {
            return $this->cookie;
        }

        if (empty($this->username) || empty($this->password)) {
            Log::error('AudiobookBayApiService: Cannot get auth cookie without username and password.');
            return ''; // Cannot authenticate
        }

        $cacheKey = $this->serviceName . '_auth_cookie_' . md5($this->username);
        $this->cookie = Cache::remember($cacheKey, 3500, function () { // Cache for slightly less than an hour
            // AudiobookBay login POST uses a specific User-Agent and form data structure
            $loginUrl = rtrim($this->baseUrl, '/') . '/member/login.php';

            try {
                $response = Http::asForm()
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0']) // ABB login seems to work better with a common UA
                    ->post($loginUrl, [
                        'username' => $this->username,
                        'password' => $this->password,
                        'login' => 'Login',
                    ]);

                if (!$response->successful()) {
                    Log::error('AudiobookBayApiService: Authentication POST failed.', [
                        'status' => $response->status(),
                        'url' => $loginUrl,
                        'response_body' => Str::limit($response->body(), 500)
                    ]);
                    return '';
                }

                $cookies = $response->cookies();
                if (empty($cookies)) {
                    Log::error('AudiobookBayApiService: No cookies found in authentication response.');
                    return '';
                }

                return collect($cookies)
                    ->map(fn ($cookie) => $cookie->getName() . '=' . $cookie->getValue())
                    ->implode('; ');

            } catch (\Exception $e) {
                Log::error('AudiobookBayApiService: Exception during authentication.', [
                    'message' => $e->getMessage(),
                    'url' => $loginUrl
                ]);
                return '';
            }
        });

        return $this->cookie ?? '';
    }

    public function searchAudiobooks(string $query, array $options = []): ?array
    {
        $endpoint = '/'; // Search is typically at the root with query parameters
        $params = [
            's' => $query,
            // AudiobookBay uses 'page_num' for pagination in search results, not 'page'
            'page_num' => $options['page'] ?? 1,
            // Common search parameters, adjust if ABB uses different ones
            // 'sort' and 'order' might not be directly supported or have different names
        ];

        if (isset($options['author'])) {
            // AudiobookBay search might incorporate author in the main query string
            // or have a specific field. For now, let's assume it's part of 's'
            // $params['author'] = $options['author']; // If there's a dedicated field
        }

        $responseObject = $this->httpGetResponse($endpoint, $params);

        Log::debug('AudiobookBayApiService:searchAudiobooks - httpGetResponse returned value', [
            'type' => gettype($responseObject),
            'class' => is_object($responseObject) ? get_class($responseObject) : null,
            'value' => $responseObject
        ]);
        if (is_string($responseObject)) {
            return $this->parseSearchResults($responseObject); // From AudiobookBayParserTrait
        } elseif ($responseObject !== null) {
            Log::error('AudiobookBayApiService:searchAudiobooks - httpGetResponse did not return a string', [
                'type' => gettype($responseObject),
                'value' => is_object($responseObject) ? get_class($responseObject) : $responseObject
            ]);
        }

        Log::warning('AudiobookBayApiService:searchAudiobooks - Failed to fetch or parse search results.', ['query' => $query, 'options' => $options]);
        return null;
    }

    public function getAudiobookDetails(string $idOrUrl): ?array
    {
        // Determine if it's a full URL or just an ID/slug
        if (str_starts_with($idOrUrl, 'http')) {
            // If it's a full URL, we need to extract the path relative to baseUrl
            if (str_starts_with($idOrUrl, $this->baseUrl)) {
                $endpoint = substr($idOrUrl, strlen($this->baseUrl));
            } else {
                Log::error('AudiobookBayApiService:getAudiobookDetails - URL does not match base URL.', ['url' => $idOrUrl, 'baseUrl' => $this->baseUrl]);
                return null; // Cannot make request to an arbitrary URL with current httpGet setup
            }
        } else {
            // Assume it's an ID or slug that needs to be appended to a base path like /audio-books/slug or /ab/slug
            // This needs to be verified with actual AudiobookBay URL structure for detail pages.
            // For now, assuming the $idOrUrl is the direct path segment after base.
            $endpoint = '/' . ltrim($idOrUrl, '/');
        }

        $responseBody = $this->httpGetResponse($endpoint);

        if (is_string($responseBody)) {
            return $this->parseAudiobookDetails($responseBody); // From AudiobookBayParserTrait
        }

        Log::warning('AudiobookBayApiService:getAudiobookDetails - Failed to fetch or parse details.', ['idOrUrl' => $idOrUrl]);
        return null;
    }

    public function getAudiobooksByAuthor(string $author, int $limit = 10): array
    {
        // AudiobookBay might not have a direct author search via query param.
        // Often, author searches are done by including the author's name in the general search query.
        $searchQuery = $author; // Simple approach: search for author's name
        return $this->searchAudiobooks($searchQuery, ['limit' => $limit]) ?? [];
    }

    public function getAudiobooksByNarrator(string $narrator, int $limit = 10): array
    {
        $searchQuery = $narrator; // Simple approach: search for narrator's name
        return $this->searchAudiobooks($searchQuery, ['limit' => $limit]) ?? [];
    }

    private function flattenToString($value): string
    {
        if (is_array($value)) {
            $flat = [];
            foreach ($value as $item) {
                $flat[] = $this->flattenToString($item);
            }
            return implode(' ', array_filter($flat));
        }
        return (string)$value;
    }
}
