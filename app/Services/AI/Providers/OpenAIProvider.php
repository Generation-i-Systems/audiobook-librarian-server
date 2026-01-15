<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Log;
use OpenAI;

class OpenAIProvider extends BaseAIProvider
{
    protected string $apiKey;
    protected string $model;
    protected $client;

    public function __construct(string $model = 'gpt-4o-mini')
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = $model;
        $this->client = OpenAI::client($this->apiKey);

        $this->rateLimits = $this->getRateLimitsForModel($model);
        $this->pricing = $this->getPricingForModel($model);
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function transcribe(string $audioPath): ?string
    {
        if (!file_exists($audioPath)) {
            Log::error('Audio file not found', ['path' => $audioPath]);
            return null;
        }

        try {
            $fileHandle = fopen($audioPath, 'r');
            if (!$fileHandle) {
                return null;
            }

            $response = $this->client->audio()->transcribe([
                'model' => 'whisper-1',
                'file' => $fileHandle,
                'response_format' => 'text',
                'language' => 'en',
            ]);

            fclose($fileHandle);

            if (is_string($response)) {
                return trim($response);
            }

            return trim((string) (data_get($response, 'text') ?? ''));
        } catch (\Exception $e) {
            Log::error('OpenAI transcription failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function callAPI(string $prompt, array $options = []): array
    {
        try {
            $response = $this->client->chat()->create([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_tokens' => $options['maxOutputTokens'] ?? 4096,
                'temperature' => $options['temperature'] ?? 0.1,
            ]);

            if (isset($response['choices'][0]['message']['content'])) {
                return [
                    'success' => true,
                    'content' => $response['choices'][0]['message']['content'],
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
        // OpenAI supports structured outputs via response_format
        try {
            $messages = [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ];

            // Add schema as a system message for guidance
            $schemaJson = json_encode($schema, JSON_PRETTY_PRINT);
            array_unshift($messages, [
                'role' => 'system',
                'content' => "Return a JSON response matching this schema:\n{$schemaJson}\n\nReturn ONLY valid JSON.",
            ]);

            $response = $this->client->chat()->create([
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $options['maxOutputTokens'] ?? 4096,
                'temperature' => $options['temperature'] ?? 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (isset($response['choices'][0]['message']['content'])) {
                return [
                    'success' => true,
                    'content' => $response['choices'][0]['message']['content'],
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

    protected function calculateUsage(array $result): array
    {
        $metadata = $result['metadata'] ?? [];
        $usage = $metadata['usage'] ?? [];

        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;
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
        return [
            'requests_per_minute' => 500,
            'requests_per_day' => null,
        ];
    }

    protected function getPricingForModel(string $model): array
    {
        $pricing = [
            'gpt-4o-mini' => ['input_per_million' => 0.15, 'output_per_million' => 0.60],
            'gpt-4o' => ['input_per_million' => 2.50, 'output_per_million' => 10.00],
            'gpt-4-turbo' => ['input_per_million' => 10.00, 'output_per_million' => 30.00],
            'gpt-3.5-turbo' => ['input_per_million' => 0.50, 'output_per_million' => 1.50],
        ];

        return $pricing[$model] ?? ['input_per_million' => 2.50, 'output_per_million' => 10.00];
    }
}
