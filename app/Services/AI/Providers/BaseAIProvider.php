<?php

namespace App\Services\AI\Providers;

use App\Contracts\AI\AIProviderInterface;
use App\Contracts\AI\AIResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class BaseAIProvider implements AIProviderInterface
{
    protected array $rateLimits;
    protected array $pricing;
    protected int $maxRetries = 3;
    protected int $retryDelayMs = 1000;
    protected float $sessionCost = 0.0;

    abstract protected function callAPI(string $prompt, array $options = []): array;

    abstract protected function callAPIWithSchema(string $prompt, array $schema, array $options = []): array;

    public function complete(string $prompt, array $options = []): AIResponse
    {
        return $this->executeWithRetry(function () use ($prompt, $options) {
            // Check rate limits
            if (!$this->canMakeRequest()) {
                return AIResponse::failure('Rate limit exceeded');
            }

            // Track request
            $this->trackRequest();

            // Call API
            $result = $this->callAPI($prompt, $options);

            if (!$result['success']) {
                return AIResponse::failure($result['error'] ?? 'Unknown error');
            }

            // Calculate cost
            $usage = $this->calculateUsage($result);
            $this->sessionCost += $usage['cost'];

            return AIResponse::success(
                content: $result['content'],
                usage: $usage,
                metadata: $result['metadata'] ?? []
            );
        });
    }

    public function completeStructured(string $prompt, array $schema, array $options = []): AIResponse
    {
        return $this->executeWithRetry(function () use ($prompt, $schema, $options) {
            // Check rate limits
            if (!$this->canMakeRequest()) {
                return AIResponse::failure('Rate limit exceeded');
            }

            // Track request
            $this->trackRequest();

            // Call API with schema
            $result = $this->callAPIWithSchema($prompt, $schema, $options);

            if (!$result['success']) {
                return AIResponse::failure($result['error'] ?? 'Unknown error');
            }

            // Parse JSON response
            $data = $this->parseStructuredResponse($result['content']);
            if ($data === null) {
                return AIResponse::failure('Failed to parse structured response');
            }

            // Validate against schema
            if (!$this->validateAgainstSchema($data, $schema)) {
                return AIResponse::failure('Response does not match expected schema');
            }

            // Calculate cost
            $usage = $this->calculateUsage($result);
            $this->sessionCost += $usage['cost'];

            return AIResponse::successWithData(
                data: $data,
                usage: $usage,
                metadata: $result['metadata'] ?? []
            );
        });
    }

    public function canMakeRequest(): bool
    {
        $cacheKey = $this->getRateLimitKey();
        $dailyCacheKey = $this->getDailyRateLimitKey();

        $minuteRequests = Cache::get($cacheKey, 0);
        $dailyRequests = Cache::get($dailyCacheKey, 0);

        $withinMinuteLimit = $minuteRequests < $this->rateLimits['requests_per_minute'];
        $withinDailyLimit = $this->rateLimits['requests_per_day'] === null ||
            $dailyRequests < $this->rateLimits['requests_per_day'];

        return $withinMinuteLimit && $withinDailyLimit;
    }

    public function getUsageStats(): array
    {
        return [
            'session_cost' => round($this->sessionCost, 4),
            'requests_this_minute' => Cache::get($this->getRateLimitKey(), 0),
            'requests_today' => Cache::get($this->getDailyRateLimitKey(), 0),
            'rate_limits' => $this->rateLimits,
            'pricing' => $this->pricing,
        ];
    }

    protected function executeWithRetry(callable $operation): AIResponse
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = $operation();

                // If successful or non-retryable error, return
                if ($response->isSuccess() || !$this->isRetryableError($response)) {
                    return $response;
                }

                $lastError = $response->getError();
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning('AI provider error', [
                    'provider' => $this->getName(),
                    'attempt' => $attempt + 1,
                    'error' => $lastError,
                ]);
            }

            $attempt++;

            if ($attempt < $this->maxRetries) {
                // Exponential backoff
                $delay = $this->retryDelayMs * (2 ** ($attempt - 1));
                usleep($delay * 1000);
            }
        }

        return AIResponse::failure($lastError ?? 'Max retries exceeded');
    }

    protected function isRetryableError(AIResponse $response): bool
    {
        if ($response->isSuccess()) {
            return false;
        }

        $error = strtolower($response->getError() ?? '');

        // Retry on rate limits, timeouts, and temporary errors
        return str_contains($error, 'rate limit') ||
            str_contains($error, 'timeout') ||
            str_contains($error, 'temporarily unavailable') ||
            str_contains($error, '429') ||
            str_contains($error, '503');
    }

    protected function trackRequest(): void
    {
        $cacheKey = $this->getRateLimitKey();
        $dailyCacheKey = $this->getDailyRateLimitKey();

        Cache::increment($cacheKey);
        Cache::put($cacheKey, Cache::get($cacheKey, 1), 60);

        Cache::increment($dailyCacheKey);
        Cache::put($dailyCacheKey, Cache::get($dailyCacheKey, 1), 24 * 60 * 60);
    }

    protected function getRateLimitKey(): string
    {
        return sprintf(
            'ai_rate_limit:%s:%s:%s',
            $this->getName(),
            $this->getModel(),
            now()->format('Y-m-d-H-i')
        );
    }

    protected function getDailyRateLimitKey(): string
    {
        return sprintf(
            'ai_rate_limit:%s:%s:%s',
            $this->getName(),
            $this->getModel(),
            now()->format('Y-m-d')
        );
    }

    protected function parseStructuredResponse(string $content): ?array
    {
        // Remove markdown code blocks
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse JSON response', [
                'provider' => $this->getName(),
                'error' => json_last_error_msg(),
                'content' => substr($content, 0, 500),
            ]);
            return null;
        }

        return $data;
    }

    protected function validateAgainstSchema(array $data, array $schema): bool
    {
        // Simple validation - check required fields exist
        $required = $schema['required'] ?? [];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                Log::warning('Missing required field in response', [
                    'provider' => $this->getName(),
                    'field' => $field,
                ]);
                return false;
            }
        }

        return true;
    }

    abstract protected function calculateUsage(array $result): array;

    protected function estimateTokens(string $text): int
    {
        // Rough estimation: ~4 characters per token
        return (int) ceil(strlen($text) / 4);
    }
}
