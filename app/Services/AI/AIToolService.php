<?php

namespace App\Services\AI;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class AIToolService
{
    protected Client $client;
    protected ?string $apiKey;
    protected string $model;
    protected array $conversationHistory = [];
    protected ToolExecutor $toolExecutor;
    protected int $maxIterations = 10;
    protected int $maxExecutionTime = 300; // 5 minutes in seconds

    public function __construct(?string $model = null)
    {
        $this->client = new Client(['timeout' => 120]);
        $this->apiKey = config('services.gemini.api_key');
        $this->model = $model ?? 'gemini-2.5-flash';
        $this->toolExecutor = new ToolExecutor();
    }

    public function processQuery(string $userQuery, array $context = []): array
    {
        try {
            $this->conversationHistory = [];
            $tools = ToolDefinitions::getAllTools();

            $initialMessage = [
                'role' => 'user',
                'parts' => [
                    ['text' => $this->buildSystemPrompt($userQuery, $context)],
                ],
            ];

            $this->conversationHistory[] = $initialMessage;

            $iteration = 0;
            $startTime = microtime(true);
            while ($iteration < $this->maxIterations) {
                $iteration++;

                if ((microtime(true) - $startTime) > $this->maxExecutionTime) {
                    Log::warning('AI query exceeded maximum execution time', [
                        'iterations' => $iteration,
                        'elapsed_seconds' => round(microtime(true) - $startTime, 2),
                    ]);
                    return [
                        'success' => false,
                        'error' => 'Query exceeded maximum execution time',
                    ];
                }

                Log::info("AI Tool Service iteration {$iteration}", [
                    'conversation_length' => count($this->conversationHistory),
                ]);

                $response = $this->callGeminiWithTools($this->conversationHistory, $tools);

                if (!$response['success']) {
                    return [
                        'success' => false,
                        'error' => $response['error'] ?? 'AI request failed',
                    ];
                }

                $candidate = $response['data'];

                if (!isset($candidate['content']['parts'])) {
                    return [
                        'success' => false,
                        'error' => 'Invalid response format from AI',
                    ];
                }

                $this->conversationHistory[] = [
                    'role' => 'model',
                    'parts' => $candidate['content']['parts'],
                ];

                $hasFunctionCall = false;
                $functionResults = [];

                foreach ($candidate['content']['parts'] as $part) {
                    if (isset($part['functionCall'])) {
                        $hasFunctionCall = true;
                        $functionName = $part['functionCall']['name'];
                        $functionArgs = $part['functionCall']['args'] ?? (object)[];

                        Log::info("AI requested tool execution", [
                            'tool' => $functionName,
                            'args' => $functionArgs,
                        ]);

                        $result = $this->toolExecutor->execute($functionName, (array)$functionArgs);

                        $functionResults[] = [
                            'functionResponse' => [
                                'name' => $functionName,
                                'response' => (object)$result,
                            ],
                        ];
                    }
                }

                if ($hasFunctionCall) {
                    $this->conversationHistory[] = [
                        'role' => 'user',
                        'parts' => $functionResults,
                    ];
                    continue;
                }

                $finalText = '';
                foreach ($candidate['content']['parts'] as $part) {
                    if (isset($part['text'])) {
                        $finalText .= $part['text'];
                    }
                }

                return [
                    'success' => true,
                    'response' => $finalText,
                    'iterations' => $iteration,
                    'conversation' => $this->conversationHistory,
                ];
            }

            return [
                'success' => false,
                'error' => 'Maximum iterations reached',
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Service failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function callGeminiWithTools(array $contents, array $tools): array
    {
        try {
            if (empty($this->apiKey)) {
                return [
                    'success' => false,
                    'error' => 'Gemini API key not configured',
                ];
            }

            $requestBody = [
                'contents' => $contents,
                'tools' => [
                    [
                        'functionDeclarations' => $this->convertToolsToGeminiFormat($tools),
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'topK' => 1,
                    'topP' => 0.8,
                    'maxOutputTokens' => 8192,
                ],
            ];

            Log::debug('Gemini API Request', [
                'model' => $this->model,
                'tools_count' => count($tools),
                'conversation_length' => count($contents),
            ]);

            $response = $this->client->post($this->getApiUrl(), [
                'query' => ['key' => $this->apiKey],
                'json' => $requestBody,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            Log::debug('Gemini API Response', [
                'has_candidates' => isset($body['candidates']),
                'candidate_count' => isset($body['candidates']) ? count($body['candidates']) : 0,
            ]);

            if (isset($body['candidates'][0])) {
                return [
                    'success' => true,
                    'data' => $body['candidates'][0],
                ];
            }

            if (isset($body['error'])) {
                return [
                    'success' => false,
                    'error' => $body['error']['message'] ?? 'Unknown API error',
                ];
            }

            return [
                'success' => false,
                'error' => 'No valid response from Gemini API',
            ];
        } catch (\Exception $e) {
            Log::error('Gemini API call failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function convertToolsToGeminiFormat(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'] ?? [
                    'type' => 'object',
                    'properties' => [],
                ],
            ];
        }, $tools);
    }

    protected function getApiUrl(): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
    }

    protected function buildSystemPrompt(string $userQuery, array $context): string
    {
        $prompt = "You are an AI assistant helping manage a large audiobook library database. ";
        $prompt .= "You have access to various tools for searching books, analyzing series, managing files, and performing bulk operations.\n\n";

        $prompt .= "Database Schema:\n";
        $prompt .= "- books: id, title, description, directory_path, release_date, language, isbn, duration, audio_file_count, ai_processed, ai_confidence\n";
        $prompt .= "- authors: id, name (many-to-many with books)\n";
        $prompt .= "- genres: id, name (many-to-many with books)\n";
        $prompt .= "- series: id, name, is_collection (many-to-many with books via book_series with series_number)\n";
        $prompt .= "- narrators: id, name (many-to-many with books)\n";
        $prompt .= "- publishers: id, name (one-to-many with books)\n\n";

        $prompt .= "Books can belong to multiple series. Each book-series relationship has a series_number indicating the book's position in that series.\n\n";

        $prompt .= "File System:\n";
        $prompt .= "- Book files are stored in: /media/lyra_data1/audiobooks/books/\n";
        $prompt .= "- Directory structure typically: {genre}/{author}/{title}/\n";
        $prompt .= "- Audio formats: .m4b, .mp3, .mp4, .ogg, .flac, .wav, .m4a\n\n";

        if (!empty($context)) {
            $prompt .= "Previous Context:\n";
            $prompt .= json_encode($context, JSON_PRETTY_PRINT) . "\n\n";
        }

        $prompt .= "User Request: {$userQuery}\n\n";

        $prompt .= "Instructions:\n";
        $prompt .= "1. Use the available tools to fulfill the user's request\n";
        $prompt .= "2. For file operations, ALWAYS preview before executing\n";
        $prompt .= "3. For series analysis, use analyze_series to find gaps and duplicates\n";
        $prompt .= "4. For complex searches, break them down into multiple tool calls if needed\n";
        $prompt .= "5. Provide clear, concise answers with relevant details\n";
        $prompt .= "6. If you need more information, ask the user\n\n";

        $prompt .= "Respond naturally and helpfully. Use the tools as needed to answer the question.";

        return $prompt;
    }

    public function getConversationHistory(): array
    {
        return $this->conversationHistory;
    }

    public function setMaxIterations(int $max): void
    {
        $this->maxIterations = $max;
    }
}
