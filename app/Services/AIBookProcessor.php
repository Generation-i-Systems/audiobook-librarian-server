<?php

namespace App\Services;

use App\Traits\IsolatesErrorHandlers;
use App\Traits\NormalizesSeriesNames;
use App\Traits\NormalizesStrings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mozex\Anthropic\Facades\Anthropic;
use OpenAI;

class AIBookProcessor
{
    use NormalizesStrings;
    use NormalizesSeriesNames;
    use IsolatesErrorHandlers;

    protected Client $client;
    protected ?string $apiKey;
    protected ?string $claudeApiKey;
    protected ?string $openaiApiKey;
    protected string $model;
    protected string $provider; // 'gemini', 'claude', or 'openai'
    protected bool $paidTier;
    protected array $modelLimits;
    protected array $modelPricing;
    protected float $estimatedCost = 0.0;

    protected array $inMemoryCache = [];

    // Model configurations with rate limits and pricing (based on official documentation)
    protected array $modelConfigs = [
        // Gemini Models (Google)
        'gemini-2.0-flash' => [
            'free' => [
                'requests_per_minute' => 15,
                'requests_per_day' => 200,  // Official: 200 RPD
                'tokens_per_minute' => 1000000, // Official: 1M TPM
                'max_output_tokens' => 8192,
            ],
            'paid' => [
                'requests_per_minute' => 1000,  // Estimated for paid tier
                'requests_per_day' => null,
                'tokens_per_minute' => 4000000,
                'max_output_tokens' => 8192,
            ],
            'pricing' => [
                'input_tokens_per_million' => 0.10,   // Official: $0.10 per 1M input tokens (text/image/video)
                'output_tokens_per_million' => 0.40,  // Official: $0.40 per 1M output tokens
            ],
        ],
        'gemini-2.0-flash-lite' => [
            'free' => [
                'requests_per_minute' => 30,    // Official: 30 RPM
                'requests_per_day' => 200,      // Official: 200 RPD
                'tokens_per_minute' => 1000000, // Official: 1M TPM
                'max_output_tokens' => 8192,
            ],
            'paid' => [
                'requests_per_minute' => 1000,
                'requests_per_day' => null,
                'tokens_per_minute' => 4000000,
                'max_output_tokens' => 8192,
            ],
            'pricing' => [
                'input_tokens_per_million' => 0.075,  // Official: $0.075 per 1M input tokens
                'output_tokens_per_million' => 0.30,  // Official: $0.30 per 1M output tokens
            ],
        ],
        'gemini-2.5-flash' => [
            'free' => [
                'requests_per_minute' => 10,    // Official: 10 RPM
                'requests_per_day' => 250,      // Official: 250 RPD
                'tokens_per_minute' => 250000,  // Official: 250K TPM
                'max_output_tokens' => 8192,
            ],
            'paid' => [
                'requests_per_minute' => 1000,
                'requests_per_day' => null,
                'tokens_per_minute' => 4000000,
                'max_output_tokens' => 8192,
            ],
            'pricing' => [
                'input_tokens_per_million' => 0.30,   // Official: $0.30 per 1M input tokens (text/image/video)
                'output_tokens_per_million' => 2.50,  // Official: $2.50 per 1M output tokens
            ],
        ],
        'gemini-2.5-flash-lite' => [
            'free' => [
                'requests_per_minute' => 15,    // Official: 15 RPM
                'requests_per_day' => 1000,     // Official: 1,000 RPD
                'tokens_per_minute' => 250000,  // Official: 250K TPM
                'max_output_tokens' => 8192,
            ],
            'paid' => [
                'requests_per_minute' => 1000,
                'requests_per_day' => null,
                'tokens_per_minute' => 4000000,
                'max_output_tokens' => 8192,
            ],
            'pricing' => [
                'input_tokens_per_million' => 0.10,   // Official: $0.10 per 1M input tokens (text/image/video)
                'output_tokens_per_million' => 0.40,  // Official: $0.40 per 1M output tokens
            ],
        ],
        'gemini-2.5-pro' => [
            'free' => [
                'requests_per_minute' => 5,     // Official: 5 RPM
                'requests_per_day' => 100,      // Official: 100 RPD
                'tokens_per_minute' => 250000,  // Official: 250K TPM
                'max_output_tokens' => 8192,
            ],
            'paid' => [
                'requests_per_minute' => 360,
                'requests_per_day' => null,
                'tokens_per_minute' => 4000000,
                'max_output_tokens' => 8192,
            ],
            'pricing' => [
                'input_tokens_per_million' => 1.25,   // Official: $1.25 per 1M (≤200K tokens), $2.50 (>200K tokens)
                'output_tokens_per_million' => 10.00, // Official: $10.00 per 1M (≤200K tokens), $15.00 (>200K tokens)
            ],
        ],

        // Claude Models (Anthropic)
        'claude-3-5-haiku' => [
            'free' => null, // No free tier for Claude API
            'paid' => [
                'requests_per_minute' => 100,  // Conservative estimate for Pro tier
                'requests_per_day' => null,    // No daily limit mentioned
                'tokens_per_minute' => 300000, // Estimate based on context window
                'max_output_tokens' => 8192,
            ],
            'pricing' => [
                'input_tokens_per_million' => 0.80,   // Official: $0.80 per 1M input tokens
                'output_tokens_per_million' => 4.00,  // Official: $4.00 per 1M output tokens
            ],
        ],
        'claude-3-5-sonnet' => [
            'free' => null, // No free tier for Claude API
            'paid' => [
                'requests_per_minute' => 50,   // Conservative estimate for API limits
                'requests_per_day' => null,
                'tokens_per_minute' => 200000,
                'max_output_tokens' => 8192,
            ],
            'pricing' => [
                'input_tokens_per_million' => 3.00,   // Official: $3.00 per 1M input tokens
                'output_tokens_per_million' => 15.00, // Official: $15.00 per 1M output tokens
            ],
        ],
        'claude-4-sonnet' => [
            'free' => null, // No free tier for Claude API
            'paid' => [
                'requests_per_minute' => 50,   // Conservative estimate
                'requests_per_day' => null,
                'tokens_per_minute' => 200000,
                'max_output_tokens' => 64000,  // Higher output token limit for Claude 4
            ],
            'pricing' => [
                'input_tokens_per_million' => 3.00,   // Official: $3.00 per 1M input tokens
                'output_tokens_per_million' => 15.00, // Official: $15.00 per 1M output tokens
            ],
        ],
        'claude-4-opus' => [
            'free' => null, // No free tier for Claude API
            'paid' => [
                'requests_per_minute' => 20,   // Lower rate for premium model
                'requests_per_day' => null,
                'tokens_per_minute' => 100000,
                'max_output_tokens' => 32000,  // High output token limit
            ],
            'pricing' => [
                'input_tokens_per_million' => 15.00,  // Official: $15.00 per 1M input tokens
                'output_tokens_per_million' => 75.00, // Official: $75.00 per 1M output tokens
            ],
        ],

        // OpenAI Models (ChatGPT)
        'gpt-4o-mini' => [
            'free' => null, // No free tier for OpenAI API
            'paid' => [
                'requests_per_minute' => 500,  // Tier 1 limits
                'requests_per_day' => null,    // No daily limit
                'tokens_per_minute' => 200000, // Tier 1: 200K TPM
                'max_output_tokens' => 16384,  // 16K max output
            ],
            'pricing' => [
                'input_tokens_per_million' => 0.15,   // Official: $0.15 per 1M input tokens
                'output_tokens_per_million' => 0.60,  // Official: $0.60 per 1M output tokens
            ],
        ],
        'gpt-4o' => [
            'free' => null, // No free tier for OpenAI API
            'paid' => [
                'requests_per_minute' => 500,  // Tier 1 limits
                'requests_per_day' => null,
                'tokens_per_minute' => 30000,  // Tier 1: 30K TPM for GPT-4o
                'max_output_tokens' => 16384,  // 16K max output
            ],
            'pricing' => [
                'input_tokens_per_million' => 2.50,   // Official: $2.50 per 1M input tokens
                'output_tokens_per_million' => 10.00, // Official: $10.00 per 1M output tokens
            ],
        ],
        'gpt-4-turbo' => [
            'free' => null, // No free tier for OpenAI API
            'paid' => [
                'requests_per_minute' => 500,  // Tier 1 limits
                'requests_per_day' => null,
                'tokens_per_minute' => 30000,  // Tier 1: 30K TPM
                'max_output_tokens' => 4096,   // 4K max output
            ],
            'pricing' => [
                'input_tokens_per_million' => 10.00,  // Official: $10.00 per 1M input tokens
                'output_tokens_per_million' => 30.00, // Official: $30.00 per 1M output tokens
            ],
        ],
        'gpt-3.5-turbo' => [
            'free' => null, // No free tier for OpenAI API
            'paid' => [
                'requests_per_minute' => 3500, // Tier 1 limits
                'requests_per_day' => null,
                'tokens_per_minute' => 160000, // Tier 1: 160K TPM
                'max_output_tokens' => 4096,   // 4K max output
            ],
            'pricing' => [
                'input_tokens_per_million' => 0.50,   // Official: $0.50 per 1M input tokens
                'output_tokens_per_million' => 1.50,  // Official: $1.50 per 1M output tokens
            ],
        ],
    ];

    public function __construct(?string $model = null, bool $paidTier = false)
    {
        $this->client = new Client([
            'timeout' => 30,
        ]);

        // Set default model and determine provider
        $this->model = $model ?: config('services.ai.default_model', 'gemini-2.5-flash-lite');
        $this->provider = $this->determineProvider($this->model);
        $this->paidTier = $paidTier;

        // Validate model exists
        if (!isset($this->modelConfigs[$this->model])) {
            throw new \InvalidArgumentException("Unsupported model: {$this->model}");
        }

        // Set API keys based on provider
        if ($this->provider === 'gemini') {
            $this->apiKey = config('services.gemini.api_key');
        } elseif ($this->provider === 'claude') {
            $this->claudeApiKey = config('services.claude.api_key');
            // For Claude, we always use paid tier (no free tier available)
            $this->paidTier = true;
        } elseif ($this->provider === 'openai') {
            $this->openaiApiKey = config('services.openai.api_key');
            // For OpenAI, we always use paid tier (no free tier available)
            $this->paidTier = true;
        }

        // Initialize OpenAI API key only for fallback audio transcription
        if (!isset($this->openaiApiKey)) {
            $this->openaiApiKey = config('services.openai.api_key');
        }

        // Set limits and pricing based on tier
        $tier = $this->paidTier ? 'paid' : 'free';

        // Claude and OpenAI models don't have free tier
        if (
            ($this->provider === 'claude' || $this->provider === 'openai') &&
            !$this->modelConfigs[$this->model]['paid']
        ) {
            throw new \InvalidArgumentException("{$this->provider} models require paid tier access");
        }

        $this->modelLimits = $this->modelConfigs[$this->model][$tier];
        $this->modelPricing = $this->modelConfigs[$this->model]['pricing'];
    }

    /**
     * Determine AI provider based on model name
     */
    protected function determineProvider(string $model): string
    {
        if (str_starts_with($model, 'gemini-')) {
            return 'gemini';
        } elseif (str_starts_with($model, 'claude-')) {
            return 'claude';
        } elseif (str_starts_with($model, 'gpt-')) {
            return 'openai';
        }

        throw new \InvalidArgumentException("Cannot determine provider for model: {$model}");
    }

    /**
     * Get the API URL for the current model
     */
    protected function getApiUrl(): string
    {
        if ($this->provider === 'gemini') {
            return "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
        } elseif ($this->provider === 'claude') {
            return "https://api.anthropic.com/v1/messages";
        } elseif ($this->provider === 'openai') {
            return "https://api.openai.com/v1/chat/completions";
        }

        throw new \InvalidArgumentException("Unsupported provider: {$this->provider}");
    }

    /**
     * Check if we can make a request within tier limits
     */
    protected function canMakeRequest(): bool
    {
        $cacheKey = "{$this->provider}_api_requests_{$this->model}_" . date('Y-m-d-H-i');
        $dailyCacheKey = "{$this->provider}_api_requests_{$this->model}_" . date('Y-m-d');

        $minuteRequests = $this->cacheGetInt($cacheKey, 0);
        $dailyRequests = $this->cacheGetInt($dailyCacheKey, 0);

        $withinMinuteLimit = $minuteRequests < $this->modelLimits['requests_per_minute'];
        $withinDailyLimit = $this->modelLimits['requests_per_day'] === null ||
            $dailyRequests < $this->modelLimits['requests_per_day'];

        return $withinMinuteLimit && $withinDailyLimit;
    }

    /**
     * Track API request for rate limiting
     */
    protected function trackRequest(): void
    {
        $cacheKey = "{$this->provider}_api_requests_{$this->model}_" . date('Y-m-d-H-i');
        $dailyCacheKey = "{$this->provider}_api_requests_{$this->model}_" . date('Y-m-d');

        $this->cacheIncrement($cacheKey, 1);
        $this->cachePut($cacheKey, $this->cacheGetInt($cacheKey, 1), 60);

        $this->cacheIncrement($dailyCacheKey, 1);
        $this->cachePut($dailyCacheKey, $this->cacheGetInt($dailyCacheKey, 1), 24 * 60 * 60);
    }

    protected function cacheGetInt(string $key, int $default = 0): int
    {
        try {
            $value = cache()->get($key, $default);
        } catch (\Throwable $e) {
            $value = $this->inMemoryCache[$key] ?? $default;
        }

        return (int) $value;
    }

    protected function cacheIncrement(string $key, int $amount = 1): void
    {
        try {
            cache()->increment($key, $amount);
        } catch (\Throwable $e) {
            $current = $this->cacheGetInt($key, 0);
            $this->inMemoryCache[$key] = $current + $amount;
        }
    }

    protected function cachePut(string $key, mixed $value, int $seconds): void
    {
        try {
            cache()->put($key, $value, $seconds);
        } catch (\Throwable $e) {
            $this->inMemoryCache[$key] = $value;
        }
    }

    /**
     * Wait if needed to respect rate limits
     */
    protected function respectRateLimit(): void
    {
        if (!$this->canMakeRequest()) {
            $this->waitForRateLimit();
        }
    }

    /**
     * Wait for rate limit window to reset
     */
    protected function waitForRateLimit(): void
    {
        $waitTime = $this->paidTier ? 1 : 60; // Paid tier waits less time
        Log::info("{$this->provider} API rate limit reached for {$this->model}, waiting {$waitTime}s...");
        sleep($waitTime);
    }

    /**
     * Process a book directory and extract metadata using AI
     */
    public function processBookDirectory(
        string $directoryPath,
        array $fileNames = [],
        array $fileTags = [],
        array $nfoData = null
    ): array {
        try {
            // Check rate limits before making request
            $this->respectRateLimit();

            $prompt = $this->buildPrompt($directoryPath, $fileNames, $fileTags, $nfoData);
            $response = $this->callAIAPI($prompt);

            return $this->parseAIResponse($response);
        } catch (\Exception $e) {
            Log::error("{$this->provider} book processing failed", [
                'directory' => $directoryPath,
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackMetadata($directoryPath, $fileNames, $fileTags);
        }
    }

    /**
     * Build the prompt for AI API (optimized for free tier)
     */
    protected function buildPrompt(
        string $directoryPath,
        array $fileNames,
        array $fileTags,
        array $nfoData = null
    ): string {
        $prompt = "Extract audiobook metadata from this data and return JSON only:\n\n";

        // Strip leading filesystem noise (e.g. /media/lyra_data1/audiobooks/unsorted) so the AI
        // only sees the meaningful part of the path (e.g. "The Messenger/01 The Messenger").
        // Keep up to the last 2 path segments to preserve series/title context.
        $displayPath = $directoryPath;
        if (str_starts_with($directoryPath, '/')) {
            $parts = array_filter(explode('/', $directoryPath), fn ($p) => $p !== '');
            if (count($parts) > 2) {
                $displayPath = implode('/', array_slice(array_values($parts), -2));
            }
        }
        $prompt .= "Directory: {$displayPath}\n";

        // Prioritize NFO data if available
        if (!empty($nfoData)) {
            $prompt .= "\nNFO FILE DATA (PRIORITY - USE THIS FIRST):\n";
            foreach ($nfoData as $key => $value) {
                if (!empty($value)) {
                    $prompt .= "{$key}: {$value}\n";
                }
            }
            $prompt .= "\n";
        }

        // Limit file names to first 3 to save tokens
        if (!empty($fileNames)) {
            $limitedFiles = array_slice($fileNames, 0, 3);
            $prompt .= "Files: " . implode(', ', $limitedFiles);
            if (count($fileNames) > 3) {
                $prompt .= " (+" . (count($fileNames) - 3) . " more)";
            }
            $prompt .= "\n";
        }

        // Include most important tags only (filter out generic/useless values)
        if (!empty($fileTags)) {
            $firstFileTags = reset($fileTags);
            $importantTags = ['title', 'artist', 'album', 'genre', 'year', 'comment'];
            $prompt .= "Tags: ";
            $tagParts = [];
            foreach ($importantTags as $key) {
                if (!empty($firstFileTags[$key])) {
                    $value = $firstFileTags[$key];
                    // Skip generic/placeholder titles that provide no useful information
                    if ($key === 'title' || $key === 'album') {
                        $lowercaseValue = strtolower($value);
                        $uselessTitlePattern = '/unknown|untitled|track\s*\d+|' .
                            'part\s*\d+|chapter\s*\d+|^no\s+title/i';
                        if (preg_match($uselessTitlePattern, $lowercaseValue)) {
                            continue; // Skip this useless tag
                        }
                    }
                    $tagParts[] = "{$key}:{$value}";
                }
            }
            if (!empty($tagParts)) {
                $prompt .= implode(', ', $tagParts) . "\n";
            } else {
                $prompt .= "No useful metadata tags found\n";
            }
        }

        $prompt .= "\nReturn JSON with these fields: title, author, narrator, series{name,number}, genre, " .
            "year, publisher, language, isbn, confidence (0-100).\n";
        $prompt .= "Clean titles (remove file artifacts, bitrates, sizes). Separate authors from narrators. " .
            "Extract series info from patterns.\n";

        if (!empty($nfoData)) {
            $prompt .= "CRITICAL: The NFO file data above is AUTHORITATIVE - use it as the primary source. " .
                "Only supplement with other data if NFO fields are missing.\n";
        }

        $prompt .= "CRITICAL INSTRUCTION: You are analyzing ONE COMPLETE AUDIOBOOK, not individual chapters. \n";
        $prompt .= "- The directory represents ONE BOOK, even if it contains multiple audio files\n";
        $prompt .= "- Do NOT extract chapter titles (like 'The Pool of Tears', 'Chapter 1', etc.) " .
            "as the main book title\n";
        $prompt .= "- Use the overall book title (like 'Alice's Adventures in Wonderland'), " .
            "not chapter names\n";
        $prompt .= "- Only use series information if this is genuinely part of a multi-book series " .
            "(Book 1, Book 2, etc.)\n";
        $prompt .= "- Individual chapters/parts within one book are NOT separate series entries\n\n";

        $prompt .= "CRITICAL: Extract metadata from directory path structure:\n";
        $prompt .= "- Directory pattern: '.../ParentDir/N BookTitle' where N is a number indicates:\n";
        $prompt .= "  * Series Name = ParentDir (e.g., 'The Forgotten Five')\n";
        $prompt .= "  * Series Number = N (e.g., 3)\n";
        $prompt .= "  * Book Title = BookTitle without the leading number " .
            "(e.g., 'Rebel Undercover' not '3 Rebel Undercover')\n";
        $prompt .= "- Example: '/The Forgotten Five/3 Rebel Undercover' → Title: 'Rebel Undercover', " .
            "Series: 'The Forgotten Five' #3\n";
        $prompt .= "- Example: '/Harry Potter/1 Philosopher's Stone' → Title: 'Philosopher's Stone', " .
            "Series: 'Harry Potter' #1\n";
        $prompt .= "- IGNORE generic ID3 tags like 'Unknown Book-PartN', 'Track N', 'Untitled' " .
            "- use directory structure instead\n";
        $prompt .= "- Always remove leading numbers/dashes from title " .
            "(e.g., '3 Title' → 'Title', '01 - Title' → 'Title')\n\n";

        $prompt .= "IMPORTANT: For genre, you MUST choose from this EXACT list of valid library genres:\n";
        $prompt .= "Church, Classic, Computer, Other, Science, Fantasy, History, General Fiction, " .
            "Action, Non Fiction, Science Fiction, LitRPG, Kids, Religion, Romance, Historical Fiction\n\n";

        // If we have a genre hint from file metadata, include it for mapping
        $genreHint = null;
        if (!empty($fileTags)) {
            $firstFileTags = reset($fileTags);
            if (!empty($firstFileTags['genre'])) {
                $genreHint = $firstFileTags['genre'];
            }
        }

        if ($genreHint !== null) {
            $prompt .= "GENRE MAPPING: The file metadata contains genre: \"{$genreHint}\"\n";
            $prompt .= "Map this to the BEST matching genre from the valid list above using these PRIORITY RULES:\n";
            $prompt .= "1. HIGH PRIORITY: LitRPG, Fantasy, Science Fiction, Romance, Kids, Religion, Church, Historical Fiction.\n";
            $prompt .= "2. MEDIUM PRIORITY: Mystery, Horror, Western, Computer, Science, Classic, History.\n";
            $prompt .= "3. LOW PRIORITY: Action (only if it is purely action without the genres above), Non Fiction.\n";
            $prompt .= "4. FALLBACK: General Fiction, Other.\n\n";
            $prompt .= "If a hierarchy like 'Science Fiction & Fantasy:Fantasy:Action' is provided, 'Fantasy' wins because it is more specific than the broad category and higher priority than 'Action'.\n\n";
        } else {
            $prompt .= "Analyze the actual story content and choose the most appropriate literary genre.\n";
            $prompt .= "Do NOT use generic terms like 'Audiobook', 'Book', 'Audio'.\n\n";
        }

        return $prompt;
    }

    /**
     * Call the AI API (Gemini or Claude)
     */
    protected function callAIAPI(string $prompt): array
    {
        try {
            // Track this request for rate limiting
            $this->trackRequest();

            // Estimate token usage for cost calculation
            $estimatedInputTokens = $this->estimateTokens($prompt);
            $estimatedOutputTokens = $this->modelLimits['max_output_tokens'] / 2; // Conservative estimate

            if ($this->provider === 'gemini') {
                return $this->callGeminiAPI($prompt, $estimatedInputTokens, $estimatedOutputTokens);
            } elseif ($this->provider === 'claude') {
                return $this->callClaudeAPI($prompt, $estimatedInputTokens, $estimatedOutputTokens);
            } elseif ($this->provider === 'openai') {
                return $this->callOpenAIAPI($prompt, $estimatedInputTokens, $estimatedOutputTokens);
            }

            throw new \InvalidArgumentException("Unsupported provider: {$this->provider}");
        } catch (GuzzleException $e) {
            Log::error("{$this->provider} API call failed", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Public helper to complete an arbitrary prompt using the configured provider/model.
     * Returns the same structure as callAIAPI():
     * ['success' => bool, 'data' => string] on success, or ['success' => false, 'error' => string] on failure.
     */
    public function complete(string $prompt): array
    {
        return $this->callAIAPI($prompt);
    }

    /**
     * Call the Gemini API
     */
    protected function callGeminiAPI(string $prompt, int $estimatedInputTokens, int $estimatedOutputTokens): array
    {
        $response = $this->client->post($this->getApiUrl(), [
            'query' => ['key' => $this->apiKey],
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1, // Low temperature for consistent results
                    'topK' => 1,
                    'topP' => 0.8,
                    'maxOutputTokens' => $this->modelLimits['max_output_tokens'],
                ],
                'safetySettings' => [
                    [
                        'category' => 'HARM_CATEGORY_HARASSMENT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HATE_SPEECH',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                ],
            ],
        ]);

        $body = json_decode($response->getBody(), true);

        if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            // Calculate actual cost if paid tier
            if ($this->paidTier) {
                $actualInputTokens = $body['usageMetadata']['promptTokenCount'] ?? $estimatedInputTokens;
                $actualOutputTokens = $body['usageMetadata']['candidatesTokenCount'] ?? $estimatedOutputTokens;
                $requestCost = $this->calculateCost($actualInputTokens, $actualOutputTokens);
                $this->estimatedCost += $requestCost;
            }

            return ['success' => true, 'data' => $body['candidates'][0]['content']['parts'][0]['text']];
        }

        return ['success' => false, 'error' => 'No valid response content'];
    }

    /**
     * Call the Claude API
     */
    protected function callClaudeAPI(string $prompt, int $estimatedInputTokens, int $estimatedOutputTokens): array
    {
        // Use the Anthropic SDK
        $response = Anthropic::messages()->create([
            'model' => $this->model,
            'max_tokens' => $this->modelLimits['max_output_tokens'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        if (isset($response['content'][0]['text'])) {
            // Calculate cost for Claude
            if ($this->paidTier) {
                $actualInputTokens = $response['usage']['input_tokens'] ?? $estimatedInputTokens;
                $actualOutputTokens = $response['usage']['output_tokens'] ?? $estimatedOutputTokens;
                $requestCost = $this->calculateCost($actualInputTokens, $actualOutputTokens);
                $this->estimatedCost += $requestCost;
            }

            return ['success' => true, 'data' => $response['content'][0]['text']];
        }

        return ['success' => false, 'error' => 'No valid response content from Claude'];
    }

    /**
     * Call the OpenAI API
     */
    protected function callOpenAIAPI(string $prompt, int $estimatedInputTokens, int $estimatedOutputTokens): array
    {
        // Use the OpenAI SDK
        $client = OpenAI::client($this->openaiApiKey);

        $response = $client->chat()->create([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens' => $this->modelLimits['max_output_tokens'],
            'temperature' => 0.1, // Low temperature for consistent results
            'top_p' => 0.8,
        ]);

        if (isset($response['choices'][0]['message']['content'])) {
            // Calculate cost for OpenAI
            if ($this->paidTier) {
                $actualInputTokens = $response['usage']['prompt_tokens'] ?? $estimatedInputTokens;
                $actualOutputTokens = $response['usage']['completion_tokens'] ?? $estimatedOutputTokens;
                $requestCost = $this->calculateCost($actualInputTokens, $actualOutputTokens);
                $this->estimatedCost += $requestCost;
            }

            return ['success' => true, 'data' => $response['choices'][0]['message']['content']];
        }

        return ['success' => false, 'error' => 'No valid response content from OpenAI'];
    }

    /**
     * Parse the AI response into structured data
     */
    protected function parseAIResponse(array $response): array
    {
        if (!$response['success']) {
            throw new \Exception($response['error']);
        }

        $jsonText = $response['data'];

        // Clean up the response to extract JSON
        $jsonText = preg_replace('/```json\s*/', '', $jsonText);
        $jsonText = preg_replace('/```\s*$/', '', $jsonText);
        $jsonText = trim($jsonText);

        $metadata = json_decode($jsonText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("Failed to parse {$this->provider} JSON response", [
                'response' => $jsonText,
                'json_error' => json_last_error_msg(),
            ]);
            throw new \Exception('Failed to parse AI response as JSON');
        }

        return $this->normalizeMetadata($metadata);
    }

    /**
     * Normalize the metadata structure
     */
    protected function normalizeMetadata(array $metadata): array
    {
        $authors = $metadata['author'] ?? ($metadata['authors'] ?? []);
        $narrators = $metadata['narrator'] ?? ($metadata['narrators'] ?? []);
        $genres = $metadata['genre'] ?? ($metadata['genres'] ?? []);

        $normalized = [
            'title' => $metadata['title'] ?? 'Unknown Title',
            'author' => $this->normalizeStringOrArray($authors),
            'narrator' => $this->normalizeStringOrArray($narrators),
            'genre' => $this->normalizeStringOrArray($genres),
            'year' => isset($metadata['year']) ? (int) $metadata['year'] : null,
            'description' => $metadata['description'] ?? null,
            'publisher' => $metadata['publisher'] ?? null,
            'duration' => $metadata['duration'] ?? '00:00:00',
            'language' => $metadata['language'] ?? 'en',
            'isbn' => $metadata['isbn'] ?? null,
            'confidence' => isset($metadata['confidence']) ? (int) $metadata['confidence'] : 50,
            'series' => null,
            'series_number' => null,
            'ai_processed' => true,
            'processed_at' => now()->toISOString(),
        ];

        // Handle series information
        if (isset($metadata['series']) && is_array($metadata['series'])) {
            $normalized['series'] = $metadata['series']['name'] ?? null;
            if (isset($metadata['series']['number'])) {
                $raw = $metadata['series']['number'];
                $normalized['series_number'] = is_numeric($raw) && str_contains((string) $raw, '.') ? (float) $raw : (int) $raw;
            }
            $normalized['is_collection'] = $metadata['series']['is_collection'] ?? false;
        }

        // CRITICAL: Adjust confidence based on missing critical fields
        // Author and narrator are essential metadata - low confidence should trigger audio transcription
        $confidencePenalty = 0;

        // Check if author is missing or empty
        $hasAuthor = count($normalized['author']) > 0;

        // Check if narrator is missing or empty
        $hasNarrator = count($normalized['narrator']) > 0;

        if (!$hasAuthor) {
            $confidencePenalty += 40; // Missing author is critical - major penalty
        }

        if (!$hasNarrator) {
            $confidencePenalty += 20; // Missing narrator is important but less critical
        }

        // Apply penalty to confidence
        if ($confidencePenalty > 0) {
            $originalConfidence = $normalized['confidence'];
            $normalized['confidence'] = max(0, $originalConfidence - $confidencePenalty);

            Log::info("Reduced confidence due to missing fields", [
                'original' => $originalConfidence,
                'adjusted' => $normalized['confidence'],
                'penalty' => $confidencePenalty,
                'has_author' => $hasAuthor,
                'has_narrator' => $hasNarrator,
            ]);
        }

        // Detect collection patterns in series name
        if (!empty($normalized['series'])) {
            $seriesName = $normalized['series'];
            $collectionPatterns = [
                '/top\s+\d+/i',                    // "Top 100", "Top 50"
                '/best\s+of/i',                    // "Best of"
                '/greatest/i',                     // "Greatest"
                '/collection/i',                   // "Collection"
                '/anthology/i',                    // "Anthology"
                '/\d+\s*essential/i',              // "100 Essential"
                '/must\s*read/i',                  // "Must Read"
                '/classics/i',                     // "Classics"
            ];

            foreach ($collectionPatterns as $pattern) {
                if (preg_match($pattern, $seriesName)) {
                    $normalized['is_collection'] = true;
                    break;
                }
            }
        }

        return $normalized;
    }

    /**
     * Normalize string or array fields
     */
    protected function normalizeStringOrArray($value): array
    {
        if (is_string($value)) {
            // If it's a comma-separated string, split it into an array
            if (strpos($value, ',') !== false) {
                return array_filter(array_map('trim', explode(',', $value)));
            }
            return [$value];
        }

        if (is_array($value)) {
            $normalized = array_map(function ($item) {
                if (is_string($item)) {
                    return trim($item);
                }
                if (is_int($item) || is_float($item)) {
                    return (string) $item;
                }

                return null;
            }, $value);

            return array_values(array_filter($normalized, static fn ($item) => $item !== null && $item !== ''));
        }

        return [];
    }

    /**
     * Get fallback metadata if AI processing fails
     */
    protected function getFallbackMetadata(string $directoryPath, array $fileNames, array $fileTags): array
    {
        // Basic extraction from directory path and file names
        $dirName = basename($directoryPath);

        // Try to extract title from directory name
        $title = $dirName;
        $title = preg_replace('/^\d+[\.\-\s]*/', '', $title); // Remove leading numbers
        $title = preg_replace('/\([^)]*\)/', '', $title); // Remove parentheses content
        $title = trim($title);

        // Try to get metadata from first audio file tags if available
        $firstFileTags = !empty($fileTags) ? reset($fileTags) : [];

        return [
            'title' => !empty($firstFileTags['title']) ? $firstFileTags['title'] : $title,
            'author' => !empty($firstFileTags['artist']) ? [$firstFileTags['artist']] : [],
            'narrator' => [],
            'genre' => !empty($firstFileTags['genre']) ? [$firstFileTags['genre']] : [],
            'year' => !empty($firstFileTags['year']) ? (int) $firstFileTags['year'] : null,
            'description' => null,
            'publisher' => null,
            'duration' => '00:00:00',
            'language' => 'en',
            'isbn' => null,
            'confidence' => 25, // Low confidence for fallback
            'series' => null,
            'series_number' => null,
            'ai_processed' => false,
            'processed_at' => now()->toISOString(),
        ];
    }

    /**
     * Extract metadata from audio file tags
     */
    public function extractFileTags(string $filePath): array
    {
        if (!class_exists('getID3')) {
            Log::warning('getID3 library not available for tag extraction');
            return [];
        }

        try {
            $fileInfo = $this->withHandlerIsolation(fn () => (new \getID3())->analyze($filePath));

            $tags = [];

            if (isset($fileInfo['tags'])) {
                // CRITICAL: For M4B files, prefer 'quicktime' tags (container level)
                // over 'id3v2' (chapter level)
                // This prevents chapter titles like "Opening Credits" from overriding the book title
                $preferredFormats = ['quicktime', 'id3v2', 'id3v1'];

                foreach ($preferredFormats as $format) {
                    if (isset($fileInfo['tags'][$format])) {
                        foreach ($fileInfo['tags'][$format] as $key => $values) {
                            if (!isset($tags[$key]) && !empty($values)) {
                                // CRITICAL: For 'title' in M4B files, use LAST value (book title)
                                // not first (chapter title)
                                // M4B files list all chapter titles first, then the book title last
                                if ($key === 'title' && is_array($values) && count($values) > 1) {
                                    $tags[$key] = end($values);
                                } else {
                                    $tags[$key] = is_array($values) ? reset($values) : $values;
                                }
                            }
                        }
                    }
                }

                // Merge any remaining tag formats not in preferred list
                foreach ($fileInfo['tags'] as $tagFormat => $tagData) {
                    if (!in_array($tagFormat, $preferredFormats)) {
                        foreach ($tagData as $key => $values) {
                            $first = is_array($values) ? reset($values) : $values;
                            if (!isset($tags[$key]) && !empty($first)) {
                                $tags[$key] = $first;
                            }
                        }
                    }
                }
            }

            // Extract cover image from QuickTime/M4B files
            if (!empty($fileInfo['comments']['picture'][0])) {
                $tags['picture'] = [
                    'data' => $fileInfo['comments']['picture'][0]['data'],
                    'mime' => $fileInfo['comments']['picture'][0]['image_mime'] ?? 'image/jpeg',
                    'type' => 'front_cover',
                ];
            }

            // Extract cover image from ID3v2 tags (MP3 files)
            if (empty($tags['picture']) && !empty($fileInfo['id3v2']['APIC'])) {
                // Try to find the front cover
                foreach ($fileInfo['id3v2']['APIC'] as $pic) {
                    if (isset($pic['picturetypeid']) && $pic['picturetypeid'] == 3) { // 3 = front cover
                        $tags['picture'] = [
                            'data' => $pic['data'],
                            'mime' => $pic['mime'] ?? 'image/jpeg',
                            'type' => 'front_cover',
                        ];
                        break;
                    }
                }

                // If no front cover found, use the first picture
                if (empty($tags['picture']) && !empty($fileInfo['id3v2']['APIC'][0]['data'])) {
                    $pic = $fileInfo['id3v2']['APIC'][0];
                    $tags['picture'] = [
                        'data' => $pic['data'],
                        'mime' => $pic['mime'] ?? 'image/jpeg',
                        'type' => 'unknown',
                    ];
                }
            }

            return $tags;
        } catch (\Exception $e) {
            Log::warning('Failed to extract audio file tags', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Get all audio files from a directory
     */
    public function getAudioFiles(string $directoryPath): array
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $audioFiles = [];

        $diskName = 'books';
        $files = Storage::disk($diskName)->files($directoryPath);

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, $audioExtensions)) {
                $audioFiles[] = $file;
            }
        }

        return $audioFiles;
    }

    /**
     * Estimate token count for a string (rough approximation)
     */
    protected function estimateTokens(string $text): int
    {
        // Rough estimation: ~4 characters per token for English text
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Calculate cost for tokens used
     */
    protected function calculateCost(int $inputTokens, int $outputTokens): float
    {
        if (!$this->paidTier) {
            return 0.0;
        }

        $inputCost = ($inputTokens / 1000000) * $this->modelPricing['input_tokens_per_million'];
        $outputCost = ($outputTokens / 1000000) * $this->modelPricing['output_tokens_per_million'];

        return $inputCost + $outputCost;
    }

    /**
     * Estimate cost for processing multiple books
     */
    public function estimateBatchCost(int $bookCount): array
    {
        if (!$this->paidTier) {
            return [
                'total_cost' => 0.0,
                'cost_per_book' => 0.0,
                'tier' => 'free',
            ];
        }

        // Average estimates based on typical book metadata
        $avgInputTokens = 150;  // Concise prompt
        $avgOutputTokens = 200; // JSON response

        $costPerBook = $this->calculateCost($avgInputTokens, $avgOutputTokens);
        $totalCost = $costPerBook * $bookCount;

        return [
            'total_cost' => round($totalCost, 4),
            'cost_per_book' => round($costPerBook, 4),
            'tier' => 'paid',
            'model' => $this->model,
            'estimated_input_tokens' => $avgInputTokens * $bookCount,
            'estimated_output_tokens' => $avgOutputTokens * $bookCount
        ];
    }

    /**
     * Get current total cost for this session
     */
    public function getTotalCost(): float
    {
        return round($this->estimatedCost, 4);
    }

    /**
     * Get model information and limits
     */
    public function getModelInfo(): array
    {
        return [
            'model' => $this->model,
            'tier' => $this->paidTier ? 'paid' : 'free',
            'limits' => $this->modelLimits,
            'pricing' => $this->paidTier ? $this->modelPricing : null,
            'total_cost' => $this->getTotalCost(),
        ];
    }

    /**
     * Reset cost tracking
     */
    public function resetCostTracking(): void
    {
        $this->estimatedCost = 0.0;
    }

    /**
     * Process audio sample for transcription and metadata extraction
     */
    public function processAudioSample(string $audioFilePath, string $directoryHint = ''): ?array
    {
        try {
            if (!file_exists($audioFilePath)) {
                Log::error("Audio file not found", ['file' => $audioFilePath]);
                return null;
            }

            Log::info("Starting audio transcription", [
                'file' => $audioFilePath,
                'hint' => $directoryHint,
                'file_size' => filesize($audioFilePath),
            ]);

            // Check rate limits before making request
            $this->respectRateLimit();

            // Use OpenAI Whisper for transcription
            $transcription = $this->transcribeAudio($audioFilePath);

            if (empty($transcription)) {
                Log::warning("No transcription received from audio file");
                return null;
            }

            Log::info("Audio transcription completed", [
                'transcription_length' => strlen($transcription),
                'preview' => substr($transcription, 0, 100) . '...'
            ]);

            // Analyze the transcription to extract metadata
            $metadata = $this->extractMetadataFromTranscription($transcription, $directoryHint);

            // Add transcription source info
            $metadata['transcription'] = $transcription;
            $metadata['source'] = 'audio_analysis';
            $metadata['ai_processed'] = true;
            $metadata['processed_at'] = now()->toISOString();

            return $metadata;
        } catch (\Exception $e) {
            Log::error("Audio analysis failed", [
                'file' => $audioFilePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Transcribe audio using the configured AI provider
     */
    protected function transcribeAudio(string $audioFilePath): ?string
    {
        try {
            Log::info("Starting audio transcription", [
                'file' => $audioFilePath,
                'file_exists' => file_exists($audioFilePath),
                'file_size' => file_exists($audioFilePath) ? filesize($audioFilePath) : 'N/A',
                'provider' => $this->provider,
                'model' => $this->model,
            ]);

            if (!file_exists($audioFilePath)) {
                Log::error("Audio file does not exist", ['file' => $audioFilePath]);
                return null;
            }

            $fileSize = filesize($audioFilePath);
            if ($fileSize === 0) {
                Log::error("Audio file is empty", ['file' => $audioFilePath]);
                return null;
            }

            // Extract first 45 seconds if file is too large
            // The intro typically contains "Written by...", "Narrated by..." metadata
            $maxSize = $this->getAudioFileSizeLimit();
            $needsExtraction = $fileSize > $maxSize;

            if ($needsExtraction) {
                Log::info("Audio file too large, extracting first 45 seconds", [
                    'file' => basename($audioFilePath),
                    'original_size_mb' => round($fileSize / (1024 * 1024), 2),
                    'max_size_mb' => round($maxSize / (1024 * 1024), 2),
                ]);

                $audioFilePath = $this->extractAudioClip($audioFilePath, 45);
                if (!$audioFilePath || !file_exists($audioFilePath)) {
                    Log::error("Failed to extract audio clip");
                    return null;
                }

                $fileSize = filesize($audioFilePath);
                Log::info("Extracted audio clip", [
                    'new_file' => basename($audioFilePath),
                    'new_size_mb' => round($fileSize / (1024 * 1024), 2),
                ]);
            }

            // Track this request for rate limiting
            $this->trackRequest();

            Log::info("Sending audio file to {$this->provider} API", [
                'file' => basename($audioFilePath),
                'size_mb' => round($fileSize / (1024 * 1024), 2),
            ]);

            // Use the configured AI provider for audio transcription
            if ($this->provider === 'gemini') {
                return $this->transcribeWithGemini($audioFilePath);
            } elseif ($this->provider === 'openai') {
                return $this->transcribeWithOpenAI($audioFilePath);
            } elseif ($this->provider === 'claude') {
                // Claude doesn't support direct audio transcription, fallback to OpenAI
                Log::info("Claude doesn't support audio transcription, falling back to OpenAI Whisper");
                return $this->transcribeWithOpenAI($audioFilePath);
            }

            Log::error("Unsupported provider for audio transcription", ['provider' => $this->provider]);
            return null;
        } catch (\Exception $e) {
            Log::error("Audio transcription failed with exception", [
                'file' => $audioFilePath,
                'provider' => $this->provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Get audio file size limit based on provider
     */
    protected function getAudioFileSizeLimit(): int
    {
        return match ($this->provider) {
            'gemini' => 2 * 1024 * 1024,  // 2MB for Gemini
            'openai' => 25 * 1024 * 1024, // 25MB for OpenAI Whisper
            'claude' => 25 * 1024 * 1024, // Fallback to OpenAI limits
            default => 2 * 1024 * 1024
        };
    }

    /**
     * Transcribe audio using Gemini
     */
    protected function transcribeWithGemini(string $audioFilePath): ?string
    {
        try {
            if (!$this->apiKey) {
                Log::error("Gemini API key not configured for audio transcription");
                return null;
            }

            // Read the audio file and encode it as base64
            $audioData = file_get_contents($audioFilePath);
            if ($audioData === false) {
                Log::error("Could not read audio file", ['file' => $audioFilePath]);
                return null;
            }

            $mimeType = $this->getAudioMimeType($audioFilePath);
            $base64Audio = base64_encode($audioData);

            $response = $this->client->post($this->getApiUrl(), [
                'query' => ['key' => $this->apiKey],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'text' => implode(' ', [
                                        'Please transcribe this audio file.',
                                        'This is the beginning of an audiobook where the title,',
                                        'author, and narrator are typically announced.',
                                        'Return only the transcribed text, no additional formatting or commentary.',
                                    ]),
                                ],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => $base64Audio,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'topK' => 1,
                        'topP' => 0.8,
                        'maxOutputTokens' => $this->modelLimits['max_output_tokens'],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                $transcription = trim($body['candidates'][0]['content']['parts'][0]['text']);

                Log::info("Gemini audio transcription completed", [
                    'transcription_length' => strlen($transcription),
                    'preview' => substr($transcription, 0, 100),
                ]);

                return $transcription;
            }

            Log::error("No valid transcription content from Gemini", ['response' => $body]);
            return null;
        } catch (\Exception $e) {
            Log::error("Gemini audio transcription failed", [
                'error' => $e->getMessage(),
                'file' => $audioFilePath,
            ]);
            return null;
        }
    }

    /**
     * Transcribe audio using OpenAI Whisper (fallback)
     */
    protected function transcribeWithOpenAI(string $audioFilePath): ?string
    {
        try {
            if (!$this->openaiApiKey) {
                Log::error("OpenAI API key not configured for audio transcription fallback");
                return null;
            }

            $client = OpenAI::client($this->openaiApiKey);

            $fileHandle = fopen($audioFilePath, 'r');
            if (!$fileHandle) {
                Log::error("Could not open audio file for reading", ['file' => $audioFilePath]);
                return null;
            }

            $response = $client->audio()->transcribe([
                'model' => 'whisper-1',
                'file' => $fileHandle,
                'response_format' => 'text',
                'language' => 'en',
            ]);

            fclose($fileHandle);

            $responseText = '';
            if (is_string($response)) {
                $responseText = $response;
            } else {
                $responseText = (string) (data_get($response, 'text') ?? '');
            }
            if ($responseText === '') {
                Log::error('OpenAI Whisper transcription returned empty response', ['file' => $audioFilePath]);
                return null;
            }

            Log::info('OpenAI Whisper transcription completed', [
                'transcription_length' => strlen($responseText),
                'preview' => substr($responseText, 0, 100),
            ]);

            return trim($responseText);
        } catch (\Exception $e) {
            Log::error("OpenAI Whisper transcription failed", [
                'error' => $e->getMessage(),
                'file' => $audioFilePath,
            ]);
            return null;
        }
    }

    /**
     * Get MIME type for audio file
     */
    /**
     * Extract a clip from an audio file using ffmpeg
     */
    protected function extractAudioClip(string $audioFilePath, int $durationSeconds = 45): ?string
    {
        try {
            // Create temp file for the extracted clip
            $tempDir = sys_get_temp_dir();
            $extension = pathinfo($audioFilePath, PATHINFO_EXTENSION);
            $outputFile = $tempDir . '/audio_clip_' . uniqid() . '.' . $extension;

            // Use ffmpeg to extract first N seconds
            $command = sprintf(
                'ffmpeg -i %s -t %d -acodec copy %s -y 2>&1',
                escapeshellarg($audioFilePath),
                $durationSeconds,
                escapeshellarg($outputFile)
            );

            Log::info("Executing ffmpeg command", [
                'command' => $command,
                'duration' => $durationSeconds,
            ]);

            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputFile)) {
                Log::error("ffmpeg extraction failed", [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output),
                    'output_file' => $outputFile,
                    'file_exists' => file_exists($outputFile),
                ]);
                return null;
            }

            Log::info("Audio clip extracted successfully", [
                'output_file' => $outputFile,
                'size' => filesize($outputFile),
            ]);

            return $outputFile;
        } catch (\Exception $e) {
            Log::error("Failed to extract audio clip", [
                'file' => $audioFilePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getAudioMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'm4a', 'm4b' => 'audio/mp4',
            'flac' => 'audio/flac',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'wma' => 'audio/x-ms-wma',
            default => 'audio/mpeg' // Default to MP3
        };
    }

    /**
     * Extract metadata from audio transcription using AI
     */
    protected function extractMetadataFromTranscription(string $transcription, string $directoryHint = ''): array
    {
        try {
            // Build a prompt to analyze the transcription
            $prompt = "Analyze this audiobook introduction transcription and extract metadata. " .
                "Return JSON only:\n\n";
            $prompt .= "TRANSCRIPTION:\n{$transcription}\n\n";

            if (!empty($directoryHint)) {
                $prompt .= "DIRECTORY HINT: {$directoryHint}\n\n";
            }

            $prompt .= "Extract: title, author, narrator, series{name,number}, genre, year, publisher, " .
                "language, confidence (0-100).\n";
            $prompt .= "This is the beginning of an audiobook where title, author, and narrator are typically " .
                "announced.\n";
            $prompt .= "Focus on the main book title, not chapter titles. Look for phrases like:\n";
            $prompt .= "- 'This is [Title] by [Author]'\n";
            $prompt .= "- 'Narrated by [Narrator]'\n";
            $prompt .= "- '[Title], Book [Number] in the [Series] series'\n";
            $prompt .= "- 'Written by [Author]', 'Read by [Narrator]'\n\n";

            $prompt .= "IMPORTANT: For genre, choose the MOST SPECIFIC literary genre. Valid genres:\n";
            $prompt .= "Kids, Religion, General Fiction, Church, Science, Historical Fiction, Computer, Classic, " .
                "History, Non Fiction, Action, LitRPG, Romance, Science Fiction, Other, Fantasy\n";
            $prompt .= "Analyze the content/story type, not just that it's an audiobook.\n";

            // Use the current AI model to analyze the transcription
            $response = $this->callAIAPI($prompt);
            $metadata = $this->parseAIResponse($response);

            // Boost confidence if we successfully extracted from audio
            if ($metadata['confidence'] > 0) {
                $metadata['confidence'] = min(95, $metadata['confidence'] + 20);
            }

            return $metadata;
        } catch (\Exception $e) {
            Log::error("Failed to extract metadata from transcription", ['error' => $e->getMessage()]);

            // Return basic fallback metadata
            return [
                'title' => 'Unknown Title (from audio)',
                'author' => [],
                'narrator' => [],
                'series' => null,
                'series_number' => null,
                'genre' => [],
                'year' => null,
                'publisher' => null,
                'language' => 'en',
                'confidence' => 10 // Very low confidence for fallback
            ];
        }
    }
}
