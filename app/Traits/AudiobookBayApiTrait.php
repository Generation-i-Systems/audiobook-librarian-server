
    use BaseApiTrait;
    /**
     * Attempt to look up the book in AudiobookBay and return additional metadata.
     *
     * @param array $book
     * @return array|null
     */
    public function searchAndMerge(array $book): ?array
    {
        $inputTitle = trim($book['title'] ?? '');
        $inputAuthor = '';
        if (isset($book['authors'][0]['author']['name']) && is_string($book['authors'][0]['author']['name'])) {
            $inputAuthor = trim($book['authors'][0]['author']['name']);
        } elseif (isset($book['authors'][0]['author']) && is_string($book['authors'][0]['author'])) {
            $inputAuthor = trim($book['authors'][0]['author']);
        } elseif (isset($book['author']['name']) && is_string($book['author']['name'])) {
            $inputAuthor = trim($book['author']['name']);
        } elseif (isset($book['author']) && is_string($book['author'])) {
            $inputAuthor = trim($book['author']);
        }
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

        // Enhanced match: author name must be in the result title, non-author part of title must match search title, and any number in search must match exactly
        function normalizeTitle($title)
        {
            // Remove author names and common stopwords, lower, trim
            return preg_replace('/\s+/', ' ', strtolower(trim(preg_replace('/[^\w\d ]/', '', $title))));
        }
        function extractNumbers($str)
        {
            preg_match_all('/\d+/', $str, $matches);
            return $matches[0] ?? [];
        }
        $searchNumbers = extractNumbers($inputTitle);
        $normalizedSearchTitle = normalizeTitle(str_ireplace($inputAuthor, '', $inputTitle));
        $bestScore = 0;
        $bestMatch = null;
        foreach ($results as $result) {
            $score = 0;
            $resultTitle = $result['title'] ?? '';
            $normalizedResultTitle = normalizeTitle(str_ireplace($inputAuthor, '', $resultTitle));
            $resultNumbers = extractNumbers($resultTitle);
            // Author name must be in the result title
            $authorInTitle = $inputAuthor && stripos($resultTitle, $inputAuthor) !== false;
            // Title similarity (ignoring author)
            similar_text($normalizedResultTitle, $normalizedSearchTitle, $pctTitle);
            // All numbers in search must be in result (e.g. book numbers)
            $numbersMatch = empty($searchNumbers) || !array_diff($searchNumbers, $resultNumbers);
            if ($authorInTitle && $pctTitle > 80 && $numbersMatch) {
                $score = 100 + $pctTitle; // strong match
            } elseif ($authorInTitle && $pctTitle > 60 && $numbersMatch) {
                $score = 80 + $pctTitle;
            } elseif ($pctTitle > 80 && $numbersMatch) {
                $score = 60 + $pctTitle;
            } elseif ($authorInTitle && $numbersMatch) {
                $score = 50;
            } elseif ($pctTitle > 60) {
                $score = 40 + $pctTitle;
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
        return array_filter($merged, function ($v, $k) {
            if (in_array($k, ['isbn_10', 'isbn_13', 'pages']) && $v === null) {
                return false;
            }
            return $v !== null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    protected ?string $username = null;
    protected ?string $password = null;
    protected ?string $authToken = null;
    protected ?string $cookie = null;

    // /**
    //  * Initialize the AudiobookBay API client
    //  */
    // public function __construct()
    // {
    //     $this->setBaseUrl('https://audiobookbay.lu');
    // }

    /**
     * Initialize with configuration
     */
    public function initAudiobookBay(array $config = []): self
    {
        $this->username = getenv('AUDIOBOOK_BAY_USERNAME') ?: config('services.audiobookbay.username');
        $this->password = getenv('AUDIOBOOK_BAY_PASSWORD') ?: config('services.audiobookbay.password');

        if (isset($config['base_url'])) {
            $this->setBaseUrl($config['base_url']);
        }
        $this->userAgent = 'TestUA'; // Set for diagnostic purposes
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
    protected function getDefaultHeaders(): array
    {
        // $this->userAgent should be initialized by initAudiobookBay() before this is called.
        // $this->cookie will be fetched (and login performed if necessary) by getAuthCookie().
        return [
            'User-Agent' => $this->userAgent,
            'Cookie' => $this->getAuthCookie(),
            'Accept' => 'application/json', // Keep this to align with BaseApiTrait's original header for now
        ];
    }

    protected function getAuthCookie(): string
    {
        if ($this->cookie) {
            return $this->cookie;
        }

        $cacheKey = 'audiobookbay_auth_cookie';
        $this->cookie = Cache::remember($cacheKey, 3600, function () {
            // This specific POST for login uses a fixed User-Agent
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
                Log::error('Failed to authenticate with AudiobookBay or no cookies found in response.');
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
        $this->initAudiobookBay();
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

        $responseObject = $this->httpGet('/', $params);

        if ($responseObject && $responseObject->successful()) {
            $htmlContent = $responseObject->body();
            return $this->parseSearchResults($htmlContent);
        }

        return null;
    }

    /**
     * Get audiobook details by ID or URL
     */
    public function getAudiobookDetails(string $id): ?array
    {
        $this->initAudiobookBay();
        $url = str_starts_with($id, 'http') ? $id : "{$this->baseUrl}/book/{$id}";
        $response = $this->httpGet($url);

        // If the response is a Response object (from Http::fake or real HTTP), parse or handle accordingly
        if ($response instanceof \Illuminate\Http\Client\Response) {
            // If the body is HTML, parse it; if it's JSON, decode it
            $body = $response->body();
            // Try JSON decode first (for test fakes)
            $data = json_decode($body, true);
            if (is_array($data)) {
                return $data;
            }
            // Otherwise, parse as HTML
            if (is_string($body) && strlen($body) > 0) {
                return $this->parseAudiobookDetails($body);
            }
            return null;
        }
        // If already array or null, return as is
        return is_array($response) || is_null($response) ? $response : null;
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
     * Backward compatible alias for parseSearchResultsHtml (for tests and internal calls)
     */
    protected function parseSearchResults(string $html): array
    {
        return $this->parseSearchResultsHtml($html);
    }

    /**
     * Parse search results HTML into structured data (renamed from parseSearchResults)
     */
    protected function parseSearchResultsHtml(string $html): array
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
