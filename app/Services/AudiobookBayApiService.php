<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AudiobookBayApiService
{
    // --- Begin inlined BaseApiTrait properties ---
    protected ?string $baseUrl = null;

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
            'coverImageUrl' => $data['coverImageUrl'] ?? $data['coverUrl'] ?? $data['cover_image_url'] ?? $data['cover_url'] ?? $data['image_url'] ?? null,
            'coverImageThumbnail' => $data['coverImageThumbnail'] ?? $data['cover_image_thumbnail'] ?? $data['thumbnail_url'] ?? null,
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
                    'dateAdded' => $data['dateAdded'] ?? $data['created_at'] ?? now()->toDateTimeString(),
                    'dateUpdated' => $data['dateUpdated'] ?? $data['updated_at'] ?? now()->toDateTimeString(),
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
        $results = $this->parseSearchResultsDom($html);
        if (!empty($results)) {
            Log::debug('[ABB-PARSE] DOM parsing returned results', ['count' => count($results)]);
            return $results;
        }

        Log::debug('[ABB-PARSE] DOM parsing failed, trying regex');
        return $this->parseSearchResultsRegex($html);
    }

    private function parseSearchResultsDom(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        $results = [];
        $items = $xpath->query('//div[contains(@class, "post")]');

        Log::debug('[ABB-PARSE] Found post items', ['count' => $items->length]);

        foreach ($items as $item) {
            $result = [
                'title' => '',
                'authors' => [],
                'narrators' => [],
                'description' => '',
                'coverImageUrl' => '',
                'url' => '',
                'metadata' => [
                    'source' => 'audiobookbay',
                ],
            ];
            $titleNode = $xpath->query('.//div[contains(@class, "postTitle")]//a', $item)->item(0);
            if ($titleNode instanceof \DOMElement && $titleNode->hasAttribute('href')) {
                $result['title'] = trim($titleNode->textContent);
                $href = $titleNode->getAttribute('href');
                $result['url'] = str_starts_with($href, 'http') ? $href : rtrim($this->baseUrl, '/') . '/' . ltrim($href, '/');
                Log::debug('[ABB-PARSE] Found title', ['title' => $result['title'], 'url' => $result['url']]);
            } else {
                Log::debug('[ABB-PARSE] No title node found');
            }
            $imgNode = $xpath->query('.//div[contains(@class, "postImg")]//img', $item)->item(0);
            if ($imgNode instanceof \DOMElement && $imgNode->hasAttribute('src')) {
                $result['coverImageUrl'] = $imgNode->getAttribute('src');
            }
            $authorNode = $xpath->query('.//div[contains(@class, "postAuthor")]//a', $item)->item(0);
            if ($authorNode instanceof \DOMNode) {
                $authorName = trim($authorNode->textContent);
                $result['authors'][] = [
                    'name' => $authorName,
                ];
                Log::debug('[ABB-PARSE] Found author', ['author' => $authorName]);
            } else {
                Log::debug('[ABB-PARSE] No author node found');
            }
            $descNode = $xpath->query('.//div[contains(@class, "postContent")]', $item)->item(0);
            if ($descNode instanceof \DOMNode) {
                $result['description'] = trim($descNode->textContent);
            }
            $infoNodes = $xpath->query('.//div[contains(@class, "postInfo")]//li', $item);
            Log::debug('[ABB-PARSE] Info nodes found', ['count' => $infoNodes->length]);
            foreach ($infoNodes as $infoNode) {
                $text = trim($infoNode->textContent);
                Log::debug('[ABB-PARSE] Info node text', ['text' => $text]);
                if (Str::startsWith($text, 'Read by:')) {
                    $narratorName = trim(Str::after($text, 'Read by:'));
                    $result['narrators'][] = [
                        'name' => $narratorName,
                    ];
                    Log::debug('[ABB-PARSE] Found narrator', ['narrator' => $narratorName]);
                }
                if (Str::startsWith($text, 'Category:')) {
                    $result['metadata']['categories'] = array_map(
                        'trim',
                        explode(',', Str::after($text, 'Category:'))
                    );
                }
                if (Str::startsWith($text, 'Language:')) {
                    $result['language'] = trim(Str::after($text, 'Language:'));
                }
                if (Str::startsWith($text, 'Format:')) {
                    $result['metadata']['format'] = trim(Str::after($text, 'Format:'));
                }
                if (Str::startsWith($text, 'Size:')) {
                    $result['metadata']['size'] = trim(Str::after($text, 'Size:'));
                }
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

    private function parseSearchResultsRegex(string $html): array
    {
        $results = [];
        $seen = [];

        if (!preg_match_all('~<a[^>]+href="([^"]+/abss/[^"]+)"[^>]*>([^<]+)</a>~i', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
            if (!str_starts_with($url, 'http')) {
                $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $rawTitle = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
            $authors = [];

            $title = $rawTitle;
            if (str_contains($rawTitle, ' - ')) {
                $parts = array_values(array_filter(array_map('trim', explode(' - ', $rawTitle))));
                if (count($parts) >= 2) {
                    $authors[] = ['name' => array_pop($parts)];
                    $title = trim(implode(' - ', $parts));
                }
            }

            $results[] = [
                'title' => $title,
                'authors' => $authors,
                'narrators' => [],
                'description' => '',
                'coverImageUrl' => '',
                'url' => $url,
                'metadata' => [
                    'source' => 'audiobookbay',
                ],
            ];
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
            'coverImageUrl' => '',
            'series' => null,
            'publisher' => null,
            'publishedDate' => null,
            'categories' => [],
            'metadata' => [],
            'language' => null,
        ];
        // Extract title from main H1 if present
        $title = '';
        $h1Nodes = $xpath->query('//h1');
        if ($h1Nodes && $h1Nodes->length > 0) {
            $title = trim($h1Nodes->item(0)->textContent);
        }
        if (!$title) {
            // Try fallback: first header node
            $headerNodes = $xpath->query('//*[self::h1 or self::h2 or self::h3][1]');
            if ($headerNodes && $headerNodes->length > 0) {
                $title = trim($headerNodes->item(0)->textContent);
            }
        }
        $book['title'] = $title;

        // Extract description and author/narrator/format from main content block
        $bodyText = '';
        $bodyNodes = $xpath->query('//body');
        if ($bodyNodes && $bodyNodes->length > 0) {
            $bodyText = $bodyNodes->item(0)->textContent;
        }
        Log::debug('AudiobookBay parse: bodyText length', ['length' => strlen($bodyText)]);
        // Use regex to extract author, narrator, format, bitrate, description
        // Make regex tolerant of any whitespace, newlines, and missing intermediate words
        if (preg_match('/Written by\s*([\s\S]+?)(?:Read by|Format:|Bitrate:|Unabridged|\n|\r|$)/i', $bodyText, $m)) {
            $book['authors'][] = ['name' => trim($m[1])];
        }
        if (preg_match('/Read by\s*([\s\S]+?)(?:Format:|Bitrate:|Unabridged|\n|\r|$)/i', $bodyText, $m)) {
            $book['narrators'][] = ['name' => trim($m[1])];
        }
        if (preg_match('/Format: ([^\n\r]+?)(?: Bitrate:| Unabridged|$)/i', $bodyText, $m)) {
            $book['metadata']['format'] = trim($m[1]);
        }
        if (preg_match('/Bitrate: ([^\n\r]+?)(?: Unabridged|$)/i', $bodyText, $m)) {
            $book['metadata']['bitrate'] = trim($m[1]);
        }
        // Description: Find <p> tags, skip the first one with metadata, get the second one
        $paragraphs = $xpath->query('//p[@itemprop="description"]/following-sibling::p[1]');
        if ($paragraphs && $paragraphs->length > 0) {
            $book['description'] = trim($paragraphs->item(0)->textContent);
        } elseif (
            preg_match(
                '/Bitrate:.*?\n\s*(.+?)(?:Announce URL:|Tracker:|Torrent Free Downloads|Start Direct Download|Download Files Now|Creation Date:|This is a Multifile|Top|$)/is',
                $bodyText,
                $m
            )
        ) {
            // Fallback to regex if XPath fails
            $book['description'] = trim($m[1]);
        }
        // Categories: look for [Category] links
        if (
            preg_match_all(
                '/\[([^\]]+)\]\(https:\/\/audiobookbay\.lu\/audio-books\/tag\/[^\)]+\)/',
                $bodyText,
                $catMatches
            )
        ) {
            $book['metadata']['categories'] = array_map('trim', $catMatches[1]);
        }
        // Language: look for [English] or similar
        if (
            preg_match(
                '/\[([A-Za-z]+)\]\(https:\/\/audiobookbay\.lu\/audio-books\/tag\/[a-z]+\)/',
                $bodyText,
                $langMatch
            )
        ) {
            $book['language'] = trim($langMatch[1]);
        }
        // Fallback: if still missing author/narrator, try to parse from any "Written by" or "Read by" line
        if (empty($book['authors']) && preg_match('/Written by ([^\n\r]+?)(?:\.|$)/i', $bodyText, $m)) {
            $book['authors'][] = ['name' => trim($m[1])];
        }
        if (empty($book['narrators']) && preg_match('/Read by ([^\n\r]+?)(?:\.|$)/i', $bodyText, $m)) {
            $book['narrators'][] = ['name' => trim($m[1])];
        }
        $imgNode = $xpath->query('//img[contains(@src,"wp-content/uploads") or contains(@src,"/uploads/") or contains(@src,"/covers/")]')->item(0);
        if (!$imgNode) {
            // Try to find any image, but exclude common non-cover images
            $allImages = $xpath->query('//img');
            foreach ($allImages as $img) {
                if ($img instanceof \DOMElement && $img->hasAttribute('src')) {
                    $src = $img->getAttribute('src');
                    // Skip search icons, logos, and other common non-cover images
                    if (
                        !str_contains($src, '/images/search.gif') &&
                        !str_contains($src, 'logo') &&
                        !str_contains($src, 'icon') &&
                        !str_contains($src, 'banner')
                    ) {
                        $imgNode = $img;
                        break;
                    }
                }
            }
        }
        if ($imgNode instanceof \DOMElement && $imgNode->hasAttribute('src')) {
            $coverUrl = $imgNode->getAttribute('src');
            // Don't use search.gif as cover
            if (!str_contains($coverUrl, '/images/search.gif')) {
                $book['coverImageUrl'] = $coverUrl;
            }
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

        $cached = Cache::get($cacheKey);

        if (!is_null($cached)) {
            if (is_string($cached)) {
                return $cached;
            }

            // Invalid data found in cache
            Log::warning('AudiobookBayApiService:httpGetResponse - Invalid data found in cache. Discarding.', [
                'key' => $cacheKey,
                'type' => gettype($cached),
            ]);

            return null; // Return null to indicate failure due to invalid cache
        }

        // Cache miss or invalid data, fetch from source
        $this->checkRateLimit();

        $fullUrl = $this->baseUrl . $endpoint;
        $queryString = http_build_query($params);
        $fullUrlWithQuery = $fullUrl . ($queryString ? '?' . $queryString : '');

        Log::debug('[ABB-REQUEST] Making HTTP request', [
            'url' => $fullUrlWithQuery,
        ]);

        $response = Http::withHeaders($this->getDefaultHeaders())
            ->timeout(20)
            ->get($fullUrl, $params);

        if ($response->successful()) {
            $responseBody = (string) $response->body();
            Cache::put($cacheKey, $responseBody, $this->cacheTtl);

            return $responseBody;
        }

        Log::error('API request failed', [
            'service' => $this->serviceName,
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return null;
    }

    /**
     * Make an HTTP POST request (inlined from BaseApiTrait)
     */
    protected function httpPost(string $endpoint, array $data = []): ?\Illuminate\Http\Client\Response
    {
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
        Cache::put($cacheKey, $count + 1, 3600);
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

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
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
    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' .
        'Chrome/91.0.4472.124 Safari/537.36';

    public function __construct(array $config = [])
    {
        $this->serviceName = 'AudiobookBay';
        $this->baseUrl = config('services.audiobook_bay.base_url');

        $this->username = $config['username'] ?? config('services.audiobook_bay.username');
        $this->password = $config['password'] ?? config('services.audiobook_bay.password');
        $this->userAgent = $config['user_agent'] ?? config('services.audiobook_bay.user_agent', $this->userAgent);

        // Initialize from BaseApiTrait or config
        $this->cacheTtl = $config['cache_ttl'] ?? $this->cacheTtl;
        $this->rateLimit = $config['rate_limit'] ?? $this->rateLimit;
    }

    /**
     * Override getDefaultHeaders from BaseApiTrait to include AudiobookBay specific cookie.
     */
    protected function getDefaultHeaders(): array
    {
        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        ];

        $cookie = $this->getAuthCookieIfConfigured();
        if (!empty($cookie)) {
            $headers['Cookie'] = $cookie;
        }

        return $headers;
    }

    protected function getAuthCookieIfConfigured(): string
    {
        if (empty($this->username) || empty($this->password)) {
            return '';
        }

        return $this->getAuthCookie();
    }

    protected function getAuthCookie(): string
    {
        if ($this->cookie) {
            return $this->cookie;
        }

        if (empty($this->username) || empty($this->password)) {
            return ''; // Cannot authenticate
        }

        $cacheKey = $this->serviceName . '_auth_cookie_' . md5($this->username);
        $this->cookie = Cache::remember($cacheKey, 3500, function () {
            // Cache for slightly less than an hour
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
                        'response_body' => Str::limit($response->body(), 500),
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
                    'url' => $loginUrl,
                ]);

                return '';
            }
        });

        return $this->cookie ?? '';
    }

    public function searchAudiobooks(string $query, array $options = []): ?array
    {
        // AudiobookBay uses /page/N/ in the path for pagination, not query params
        $page = $options['page'] ?? 1;
        $endpoint = $page > 1 ? "/page/$page/" : '/';

        // AudiobookBay requires lowercase search queries
        $params = [
            's' => strtolower($query),
            'cat' => 'undefined,undefined', // Required by AudiobookBay
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
            'value' => $responseObject,
        ]);
        if (is_string($responseObject)) {
            return $this->parseSearchResults($responseObject); // From AudiobookBayParserTrait
        } elseif ($responseObject !== null) {
            Log::error('AudiobookBayApiService:searchAudiobooks - httpGetResponse did not return a string', [
                'type' => gettype($responseObject),
                'value' => is_object($responseObject) ? get_class($responseObject) : $responseObject,
            ]);
        }

        Log::warning('AudiobookBayApiService:searchAudiobooks - Failed to fetch or parse search results.', [
            'query' => $query,
            'options' => $options,
        ]);

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
                Log::error('AudiobookBayApiService:getAudiobookDetails - URL does not match base URL.', [
                    'url' => $idOrUrl,
                    'baseUrl' => $this->baseUrl,
                ]);

                return null; // Cannot make request to an arbitrary URL with current httpGet setup
            }
        } else {
            $candidate = '/' . ltrim($idOrUrl, '/');

            // If user passes just a slug (what our search results expose as apiId), map to /abss/{slug}/
            if (!str_contains($candidate, '/abss/') && !str_contains($candidate, '/audio-books/')) {
                $candidate = '/abss/' . trim($idOrUrl, "/") . '/';
            }

            $endpoint = $candidate;
        }

        $responseBody = $this->httpGetResponse($endpoint);

        if (is_string($responseBody)) {
            $parsed = $this->parseAudiobookDetails($responseBody); // From AudiobookBayParserTrait
            $fullUrl = rtrim($this->baseUrl, '/') . $endpoint;
            $urlPath = (string) (parse_url($fullUrl, PHP_URL_PATH) ?? '');
            $urlPath = rtrim($urlPath, '/');

            $parsed['url'] = $parsed['url'] ?? $fullUrl;
            $parsed['id'] = $parsed['id'] ?? basename($urlPath);

            return $parsed;
        }

        Log::warning('AudiobookBayApiService:getAudiobookDetails - Failed to fetch or parse details.', [
            'idOrUrl' => $idOrUrl,
        ]);

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

        return (string) $value;
    }

    /**
     * Expose the authentication cookie for legacy/test code compatibility.
     */
    public function getAudiobookBayCookie(): ?string
    {
        return $this->getAuthCookie();
    }

    /**
     * Legacy/test compatibility: alias for searchAudiobooks.
     *
     * @return array|null
     */
    public function audiobookBaySearch(string $query, array $options = [])
    {
        return $this->searchAudiobooks($query, $options);
    }
}
