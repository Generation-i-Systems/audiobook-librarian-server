<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Log;
use Mozex\Anthropic\Facades\Anthropic;

class ClaudeProvider extends BaseAIProvider
{
    protected string $apiKey;
    protected string $model;

    public function __construct(string $model = 'claude-3-5-haiku')
    {
        $this->apiKey = config('services.claude.api_key');
        $this->model = $model;

        $this->rateLimits = $this->getRateLimitsForModel($model);
        $this->pricing = $this->getPricingForModel($model);
    }

    public function getName(): string
    {
        return 'claude';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function transcribe(string $audioPath): ?string
    {
        // Claude doesn't support audio transcription natively
        Log::info('Claude does not support audio transcription, use OpenAI provider');
        return null;
    }

    protected function callAPI(string $prompt, array $options = []): array
    {
        try {
            $response = Anthropic::messages()->create([
                'model' => $this->model,
                'max_tokens' => $options['maxOutputTokens'] ?? 8192,
                'temperature' => $options['temperature'] ?? 0.1,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

            if (isset($response['content'][0]['text'])) {
                return [
                    'success' => true,
                    'content' => $response['content'][0]['text'],
                    'metadata' => [
                        'usage' => $response['usage'] ?? [],
                    ],
                ];
            }

            return ['success' => false, 'error' => 'No valid response content'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function callAPIWithSchema(string $prompt, array $schema, array $options = []): array
    {
        // Add JSON schema instruction
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT);
        $enhancedPrompt = "{$prompt}\n\nReturn a JSON response matching this schema:\n{$schemaJson}\n\nIMPORTANT: Return ONLY valid JSON, no markdown formatting or explanation.";

        return $this->callAPI($enhancedPrompt, $options);
    }

    protected function calculateUsage(array $result): array
    {
        $metadata = $result['metadata'] ?? [];
        $usage = $metadata['usage'] ?? [];

        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $totalTokens = $inputTokens + $outputTokens;

        $inputCost = ($inputTokens / 1_000_000) * $this->pricing['input_per_million'];
        $outputCost = ($outputTokens / 1_000_000) * $this->pricing['output_per_million'];
        $totalCost = $inputCost + $outputCost;

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost' => round($totalCost, 6),
        ];
    }

    protected function getRateLimitsForModel(string $model): array
    {
        // All Claude models are paid tier
        return [
            'requests_per_minute' => 100,
            'requests_per_day' => null,
        ];
    }

    protected function getPricingForModel(string $model): array
    {
        $pricing = [
            'claude-3-5-haiku' => ['input_per_million' => 0.80, 'output_per_million' => 4.00],
            'claude-3-5-sonnet' => ['input_per_million' => 3.00, 'output_per_million' => 15.00],
            'claude-4-sonnet' => ['input_per_million' => 3.00, 'output_per_million' => 15.00],
            'claude-4-opus' => ['input_per_million' => 15.00, 'output_per_million' => 75.00],
        ];

        return $pricing[$model] ?? ['input_per_million' => 3.00, 'output_per_million' => 15.00];
    }
}
