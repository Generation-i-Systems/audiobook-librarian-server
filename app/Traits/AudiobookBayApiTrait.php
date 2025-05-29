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
