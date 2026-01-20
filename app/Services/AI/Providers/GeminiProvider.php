<?php

namespace App\Services\AI\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class GeminiProvider extends BaseAIProvider
{
    protected Client $client;
    protected string $apiKey;
    protected string $model;

    public function __construct(string $model = 'gemini-2.5-flash-lite', bool $paidTier = false)
    {
        $this->client = new Client(['timeout' => 30]);
        $this->apiKey = (string) config('services.gemini.api_key');
        $this->model = $model;

        // Set rate limits and pricing based on model and tier
        $this->rateLimits = $this->getRateLimitsForModel($model, $paidTier);
        $this->pricing = $this->getPricingForModel($model);
    }

    public function getName(): string
    {
        return 'gemini';
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
            $audioData = file_get_contents($audioPath);
            $mimeType = $this->getAudioMimeType($audioPath);
            $base64Audio = base64_encode($audioData);

            $response = $this->client->post($this->getApiUrl(), [
                'query' => ['key' => $this->apiKey],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => 'Transcribe this audio file. Return only the transcribed text.'],
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
                        'maxOutputTokens' => 2048,
                    ],
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            return $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        } catch (\Exception $e) {
            Log::error('Gemini transcription failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function callAPI(string $prompt, array $options = []): array
    {
        try {
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
                        'temperature' => $options['temperature'] ?? 0.1,
                        'topK' => $options['topK'] ?? 40,
                        'topP' => $options['topP'] ?? 0.95,
                        'maxOutputTokens' => $options['maxOutputTokens'] ?? 8192,
                    ],
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                return [
                    'success' => true,
                    'content' => $body['candidates'][0]['content']['parts'][0]['text'],
                    'metadata' => [
                        'usage' => $body['usageMetadata'] ?? [],
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
        // Add JSON schema instruction to prompt
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT);
        $enhancedPrompt = "{$prompt}\n\nReturn a JSON response matching this schema:\n{$schemaJson}\n\nIMPORTANT: Return ONLY valid JSON, no markdown formatting or explanation.";

        return $this->callAPI($enhancedPrompt, $options);
    }

    protected function calculateUsage(array $result): array
    {
        $metadata = $result['metadata'] ?? [];
        $usage = $metadata['usage'] ?? [];

        $inputTokens = $usage['promptTokenCount'] ?? 0;
        $outputTokens = $usage['candidatesTokenCount'] ?? 0;
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

    protected function getApiUrl(): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
    }

    protected function getRateLimitsForModel(string $model, bool $paidTier): array
    {
        $limits = [
            'gemini-2.5-flash-lite' => [
                'free' => ['requests_per_minute' => 15, 'requests_per_day' => 1000],
                'paid' => ['requests_per_minute' => 1000, 'requests_per_day' => null],
            ],
            'gemini-2.5-flash' => [
                'free' => ['requests_per_minute' => 10, 'requests_per_day' => 250],
                'paid' => ['requests_per_minute' => 1000, 'requests_per_day' => null],
            ],
            'gemini-2.0-flash' => [
                'free' => ['requests_per_minute' => 15, 'requests_per_day' => 200],
                'paid' => ['requests_per_minute' => 1000, 'requests_per_day' => null],
            ],
        ];

        $tier = $paidTier ? 'paid' : 'free';
        return $limits[$model][$tier] ?? ['requests_per_minute' => 10, 'requests_per_day' => 100];
    }

    protected function getPricingForModel(string $model): array
    {
        $pricing = [
            'gemini-2.5-flash-lite' => ['input_per_million' => 0.10, 'output_per_million' => 0.40],
            'gemini-2.5-flash' => ['input_per_million' => 0.30, 'output_per_million' => 2.50],
            'gemini-2.0-flash' => ['input_per_million' => 0.10, 'output_per_million' => 0.40],
        ];

        return $pricing[$model] ?? ['input_per_million' => 0.10, 'output_per_million' => 0.40];
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
            default => 'audio/mpeg'
        };
    }
}
