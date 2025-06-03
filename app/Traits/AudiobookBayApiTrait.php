<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Trait for interacting with the AudiobookBay API
 */
trait AudiobookBayApiTrait
{
    /**
     * Attempt to look up the book in AudiobookBay and return additional metadata.
     *
     * @param array $book
     * @return array|null
     */
    public function searchAndMerge(array $book): ?array
    {
        $inputTitle = trim($book['title'] ?? '');
        $inputAuthor = trim(($book['authors'][0]['author']['name'] ?? '') ?: ($book['author'] ?? ''));
        if (!$inputTitle) {
            return null;
        }

        // Search AudiobookBay by title (and author if available)
        $query = $inputTitle;
        $options = [];
        if ($inputAuthor) {
            $options['author'] = $inputAuthor;
        }
        $results = $this->searchAudiobooks($query, $options) ?? [];
        if (empty($results)) {
            return null;
        }

        // Score and find best match
        $bestScore = 0;
        $bestMatch = null;
        foreach ($results as $result) {
            $score = 0;
            if (!empty($result['title']) && stripos($result['title'], $inputTitle) !== false) {
                $score += 3;
            } elseif (!empty($result['title']) && similar_text(strtolower($result['title']), strtolower($inputTitle), $pct) && $pct > 80) {
                $score += 2;
            }
            if (!empty($inputAuthor) && !empty($result['authors'])) {
                foreach ($result['authors'] as $authorObj) {
                    $authorName = is_array($authorObj['author'] ?? null) ? $authorObj['author']['name'] ?? '' : ($authorObj['author'] ?? '');
                    if ($authorName && stripos($authorName, $inputAuthor) !== false) {
                        $score += 2;
                        break;
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $result;
            }
        }
        if (!$bestMatch) {
            return null;
        }

        // Fetch more details if possible
        $details = null;
        if (!empty($bestMatch['id'])) {
            $details = $this->getAudiobookDetails($bestMatch['id']);
        }
        $source = $details ?: $bestMatch;
        $merged = [
            'audiobookbay_id' => $source['id'] ?? null,
            'title' => $source['title'] ?? null,
            'subtitle' => $source['subtitle'] ?? null,
            'description' => $source['description'] ?? null,
            'cover_image' => $source['cover_image_url'] ?? null,
            'authors' => $source['authors'] ?? null,
            'publisher' => $source['publisher']['name'] ?? $source['publisher_name'] ?? null,
            'release_date' => $source['published_date'] ?? $source['release_date'] ?? null,
            'series' => $source['series'] ?? null,
            'categories' => $source['categories'] ?? null,
            'duration' => $source['duration'] ?? null,
            'url' => $source['url'] ?? null,
            'language' => $source['language'] ?? null,
        ];
        // Download cover image if present and directory_path is available
        if (!empty($merged['cover_image']) && !empty($book['directory_path'])) {
            $coverUrl = $merged['cover_image'];
            $directory = rtrim($book['directory_path'], '/');
            $ext = pathinfo(parse_url($coverUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $localFilename = $directory . '/cover.' . $ext;
            try {
                if (class_exists('Illuminate\\Support\\Facades\\Http')) {
                    $response = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])->get($coverUrl);
                    if ($response->successful()) {
                        file_put_contents($localFilename, $response->body());
                        $merged['cover_image'] = $localFilename;
                    }
                } else {
                    $imageData = @file_get_contents($coverUrl);
                    if ($imageData !== false) {
                        file_put_contents($localFilename, $imageData);
                        $merged['cover_image'] = $localFilename;
                    }
                }
            } catch (\Exception $e) {
                if (class_exists('Illuminate\\Support\\Facades\\Log')) {
                    \Illuminate\Support\Facades\Log::warning('Failed to download cover image', ['url' => $coverUrl, 'error' => $e->getMessage()]);
                }
            }
        }
        $apiFields = [];
        $needsReview = false;
        foreach ($merged as $field => $newValue) {
            if (array_key_exists($field, $book) && $book[$field] !== null && $newValue !== null && $book[$field] != $newValue) {
                $apiFields[$field] = $newValue;
                $needsReview = true;
                // Overwrite merged value with original for main record
                $merged[$field] = $book[$field];
            }
        }
        if ($needsReview) {
            $merged['audiobookbay_fields'] = $apiFields;
            $merged['needsReview'] = true;
        }
        // Remove nulls and skip ISBN/pages if not present
        return array_filter($merged, function($v, $k) {
            if (in_array($k, ['isbn_10', 'isbn_13', 'pages']) && $v === null) {
                return false;
            }
            return $v !== null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    use BaseApiTrait;

    protected ?string $username = null;
    protected ?string $password = null;
    protected ?string $authToken = null;
    protected ?string $cookie = null;

    /**
     * Initialize the AudiobookBay API client
     */
    public function __construct()
    {
        $this->setBaseUrl('https://audiobookbay.lu');
    }

    /**
     * Initialize with configuration
     */
    public function initAudiobookBay(array $config = []): self
    {
        $this->username = $config['username'] ?? config('services.audiobookbay.username');
        $this->password = $config['password'] ?? config('services.audiobookbay.password');
        
        if (isset($config['base_url'])) {
            $this->setBaseUrl($config['base_url']);
        }
        
        return $this;
    }
    
    /**
     * Search for books
     */
    public function searchBooks(string $query, array $options = []): array
    {
        return $this->searchAudiobooks($query, $options) ?? [];
    }
    
    /**
     * Get book details by ID
     */
    public function getBookDetails(string $id): array
    {
        return $this->getAudiobookDetails($id) ?? [];
    }
    
    /**
     * Login to AudiobookBay
     */
    public function login(): bool
    {
        if (empty($this->username) || empty($this->password)) {
            Log::warning('AudiobookBay credentials not fully configured');
            return false;
        }
        
        // Implementation for login would go here
        return true;
    }

    /**
     * Get the authentication cookie
     */
    protected function getAuthCookie(): string
    {
        if ($this->cookie) {
            return $this->cookie;
        }

        $cacheKey = 'audiobookbay_auth_cookie';
        $this->cookie = Cache::remember($cacheKey, 3600, function () {
            $response = Http::asForm()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                ])
                ->post($this->baseUrl . '/member/login.php', [
                    'username' => $this->username,
                    'password' => $this->password,
                    'login' => 'Login',
                ]);

            if (!$response->successful() || count($response->cookies()) === 0) {
                Log::error('Failed to authenticate with AudiobookBay');
                return '';
            }

            return collect($response->cookies())
                ->map(fn ($cookie) => "{$cookie->getName()}={$cookie->getValue()}")
                ->implode('; ');
        });

        return $this->cookie;
    }

    /**
     * Search for audiobooks
     */
    public function searchAudiobooks(string $query, array $options = []): ?array
    {
        $params = [
            's' => $query,
            'page' => $options['page'] ?? 1,
            'orderby' => $options['sort'] ?? 'relevance',
            'order' => $options['order'] ?? 'desc',
        ];
        
        if (isset($options['author'])) {
            $params['author'] = $options['author'];
        }
        
        if (isset($options['narrator'])) {
            $params['narrator'] = $options['narrator'];
        }
        
        $response = $this->makeRequest('GET', '/', $params);
        
        if (!$response || empty($response['html'])) {
            return null;
        }
        
        return $this->parseSearchResults($response['html']);
    }

    /**
     * Get audiobook details by ID or URL
     */
    public function getAudiobookDetails(string $id): ?array
    {
        $url = str_starts_with($id, 'http') ? $id : "{$this->baseUrl}/book/{$id}";
        $response = $this->makeRequest('GET', $url);
        
        if (!$response || empty($response['html'])) {
            return null;
        }
        
        return $this->parseAudiobookDetailsApi($response['html']);
    }
    
    /**
     * Get audiobooks by author
     */
    public function getAudiobooksByAuthor(string $author, int $limit = 10): array
    {
        return $this->searchAudiobooks('', ['author' => $author, 'limit' => $limit]) ?? [];
    }
    
    /**
     * Get audiobooks by narrator
     */
    public function getAudiobooksByNarrator(string $narrator, int $limit = 10): array
    {
        return $this->searchAudiobooks('', ['narrator' => $narrator, 'limit' => $limit]) ?? [];
    }
    
    /**
     * Parse search results HTML into structured data
     */
    protected function parseSearchResults(string $html): array
    {
        // Implementation for parsing search results
        return [];
    }
    
    /**
     * Parse audiobook details from HTML (API version)
     */
    protected function parseAudiobookDetailsApi(string $html): array
    {
        // Implementation for parsing audiobook details
        return [];
    }
}
