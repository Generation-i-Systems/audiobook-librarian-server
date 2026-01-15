<?php

namespace App\Services\AI;

use App\Contracts\AI\AIProviderInterface;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AIAssistantService
{
    protected AIProviderInterface $provider;
    protected array $conversationHistory = [];

    public function __construct(?string $provider = null, ?string $model = null)
    {
        $providerName = $provider ?? config('services.ai.default_provider', 'gemini');
        $model = $model ?? config('services.ai.default_model', 'gemini-2.5-flash-lite');

        $this->provider = match ($providerName) {
            'claude' => new ClaudeProvider($model),
            'openai' => new OpenAIProvider($model),
            default => new GeminiProvider($model),
        };
    }

    /**
     * Process a user request for book operations
     * Returns a session with preview of operations
     */
    public function processRequest(string $userMessage, ?int $sessionId = null, ?int $userId = null): array
    {
        // Load existing session if provided
        if ($sessionId) {
            $session = DB::table('ai_assistant_sessions')->where('id', $sessionId)->first();
            if (!$session) {
                return ['success' => false, 'error' => 'Session not found'];
            }

            $this->conversationHistory = json_decode($session->conversation_history, true) ?? [];
        }

        // Add user message to conversation
        $this->conversationHistory[] = [
            'role' => 'user',
            'content' => $userMessage,
            'timestamp' => now()->toISOString(),
        ];

        // Analyze the request
        $analysis = $this->analyzeRequest($userMessage);

        if (!$analysis['success']) {
            return $analysis;
        }

        // Generate operations based on analysis
        $operations = $this->generateOperations($analysis['intent'], $analysis['parameters']);

        if (!$operations['success']) {
            return $operations;
        }

        // Add assistant response to conversation
        $this->conversationHistory[] = [
            'role' => 'assistant',
            'content' => $operations['explanation'],
            'operations' => $operations['operations'],
            'timestamp' => now()->toISOString(),
        ];

        // Save or update session
        if ($sessionId) {
            DB::table('ai_assistant_sessions')
                ->where('id', $sessionId)
                ->update([
                    'conversation_history' => json_encode($this->conversationHistory),
                    'last_intent' => $analysis['intent'],
                    'operations' => json_encode($operations['operations']),
                    'status' => 'pending_approval',
                    'updated_at' => now(),
                ]);
        } else {
            $sessionId = DB::table('ai_assistant_sessions')->insertGetId([
                'user_id' => $userId,
                'conversation_history' => json_encode($this->conversationHistory),
                'last_intent' => $analysis['intent'],
                'operations' => json_encode($operations['operations']),
                'status' => 'pending_approval',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'success' => true,
            'session_id' => $sessionId,
            'intent' => $analysis['intent'],
            'explanation' => $operations['explanation'],
            'operations' => $operations['operations'],
            'affected_count' => count($operations['operations']),
        ];
    }

    /**
     * Analyze user request to determine intent and parameters
     */
    protected function analyzeRequest(string $userMessage): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['intent', 'parameters'],
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => ['search', 'update', 'delete', 'create', 'tag', 'organize', 'clarify'],
                ],
                'parameters' => ['type' => 'object'],
                'confidence' => ['type' => 'number'],
            ],
        ];

        $prompt = $this->buildAnalysisPrompt($userMessage);
        $response = $this->provider->completeStructured($prompt, $schema);

        if (!$response->isSuccess()) {
            return ['success' => false, 'error' => $response->getError()];
        }

        $data = $response->getData();

        return [
            'success' => true,
            'intent' => $data['intent'],
            'parameters' => $data['parameters'],
            'confidence' => $data['confidence'] ?? 0.8,
        ];
    }

    /**
     * Generate specific operations based on intent
     */
    protected function generateOperations(string $intent, array $parameters): array
    {
        return match ($intent) {
            'search' => $this->generateSearchResults($parameters),
            'update' => $this->generateUpdateOperations($parameters),
            'delete' => $this->generateDeleteOperations($parameters),
            'create' => $this->generateCreateOperations($parameters),
            'tag' => $this->generateTagOperations($parameters),
            'organize' => $this->generateOrganizeOperations($parameters),
            'clarify' => ['success' => false, 'error' => 'Need more information', 'needs_clarification' => true],
            default => ['success' => false, 'error' => 'Unknown intent'],
        };
    }

    protected function buildAnalysisPrompt(string $userMessage): string
    {
        $conversationContext = '';
        if (count($this->conversationHistory) > 1) {
            $recent = array_slice($this->conversationHistory, -4, -1);
            $conversationContext = "\n\nRecent conversation:\n" .
                collect($recent)->map(fn ($msg) => "{$msg['role']}: {$msg['content']}")->join("\n");
        }

        return <<<PROMPT
You are an AI assistant managing a book/audiobook library database.

Analyze this user request and determine the intent and parameters.

User request: "{$userMessage}"{$conversationContext}

Available intents:
- search: Find books matching criteria
- update: Modify book metadata (title, author, genre, series, etc.)
- delete: Remove books or files
- create: Add new books
- tag: Add/modify tags or categories
- organize: Reorganize files or directory structure
- clarify: Need more information from user

Return JSON with:
- intent: one of the intents above
- parameters: object with relevant search criteria, field changes, etc.
- confidence: 0-1 how confident you are

Examples:
"Find all sci-fi books by Brandon Sanderson" -> {intent: "search", parameters: {genre: "Science Fiction", author: "Brandon Sanderson"}}
"Change the author of 'Mistborn' to 'Brandon Sanderson'" -> {intent: "update", parameters: {search: {title: "Mistborn"}, changes: {author: "Brandon Sanderson"}}}
"Delete all books with no author" -> {intent: "delete", parameters: {criteria: {author: null}}}
"Mark all Terry Pratchett books as 'Fantasy'" -> {intent: "update", parameters: {search: {author: "Terry Pratchett"}, changes: {genre: "Fantasy"}}}
PROMPT;
    }

    protected function generateSearchResults(array $parameters): array
    {
        $query = DB::table('books')->select('id', 'title', 'author', 'narrator', 'genre', 'series', 'year', 'file_path');

        // Apply search criteria
        if (isset($parameters['title'])) {
            $query->where('title', 'like', "%{$parameters['title']}%");
        }
        if (isset($parameters['author'])) {
            $query->where('author', 'like', "%{$parameters['author']}%");
        }
        if (isset($parameters['genre'])) {
            $query->where('genre', 'like', "%{$parameters['genre']}%");
        }
        if (isset($parameters['series'])) {
            $query->where('series', 'like', "%{$parameters['series']}%");
        }
        if (isset($parameters['year'])) {
            $query->where('year', $parameters['year']);
        }
        if (isset($parameters['has_cover']) && $parameters['has_cover'] === false) {
            $query->whereNull('cover_image');
        }
        if (isset($parameters['missing_metadata'])) {
            if ($parameters['missing_metadata'] === 'author') {
                $query->where(function ($q) {
                    $q->whereNull('author')->orWhere('author', '');
                });
            }
            if ($parameters['missing_metadata'] === 'genre') {
                $query->where(function ($q) {
                    $q->whereNull('genre')->orWhere('genre', '');
                });
            }
        }

        $books = $query->limit(100)->get()->toArray();

        if (empty($books)) {
            return [
                'success' => false,
                'error' => 'No books found matching criteria',
            ];
        }

        return [
            'success' => true,
            'explanation' => "Found " . count($books) . " book(s) matching your criteria",
            'operations' => array_map(fn ($book) => [
                'type' => 'display',
                'book_id' => $book->id,
                'title' => $book->title,
                'author' => $book->author,
                'narrator' => $book->narrator,
                'genre' => $book->genre,
                'series' => $book->series,
                'year' => $book->year,
                'file_path' => $book->file_path,
            ], $books),
        ];
    }

    protected function generateUpdateOperations(array $parameters): array
    {
        // First find matching books
        $searchParams = $parameters['search'] ?? $parameters;
        $changes = $parameters['changes'] ?? [];

        if (empty($changes)) {
            return ['success' => false, 'error' => 'No changes specified'];
        }

        $searchResult = $this->generateSearchResults($searchParams);
        if (!$searchResult['success']) {
            return $searchResult;
        }

        $operations = [];
        foreach ($searchResult['operations'] as $book) {
            $operations[] = [
                'type' => 'update',
                'book_id' => $book['book_id'],
                'current' => $book,
                'changes' => $changes,
                'preview' => array_merge($book, $changes),
            ];
        }

        $changeList = collect($changes)->map(fn ($v, $k) => "{$k} → {$v}")->join(', ');

        return [
            'success' => true,
            'explanation' => "Will update " . count($operations) . " book(s): {$changeList}",
            'operations' => $operations,
        ];
    }

    protected function generateDeleteOperations(array $parameters): array
    {
        $searchResult = $this->generateSearchResults($parameters['criteria'] ?? $parameters);
        if (!$searchResult['success']) {
            return $searchResult;
        }

        $deleteFiles = $parameters['delete_files'] ?? false;

        $operations = [];
        foreach ($searchResult['operations'] as $book) {
            $operations[] = [
                'type' => 'delete',
                'book_id' => $book['book_id'],
                'title' => $book['title'],
                'file_path' => $book['file_path'],
                'delete_files' => $deleteFiles,
            ];
        }

        $fileNote = $deleteFiles ? ' (including files)' : ' (database only)';

        return [
            'success' => true,
            'explanation' => "Will delete " . count($operations) . " book(s){$fileNote}",
            'operations' => $operations,
        ];
    }

    protected function generateCreateOperations(array $parameters): array
    {
        return [
            'success' => true,
            'explanation' => "Will create new book with provided metadata",
            'operations' => [
                [
                    'type' => 'create',
                    'metadata' => $parameters,
                ],
            ],
        ];
    }

    protected function generateTagOperations(array $parameters): array
    {
        // Similar to update but specifically for tags
        return $this->generateUpdateOperations([
            'search' => $parameters['search'] ?? $parameters,
            'changes' => ['genre' => $parameters['tag'] ?? $parameters['genre']],
        ]);
    }

    protected function generateOrganizeOperations(array $parameters): array
    {
        // Placeholder for file organization operations
        return [
            'success' => false,
            'error' => 'File organization not yet implemented',
        ];
    }

    /**
     * Execute approved operations
     */
    public function executeOperations(int $sessionId, array $selectedBookIds = []): array
    {
        $session = DB::table('ai_assistant_sessions')->where('id', $sessionId)->first();
        if (!$session) {
            return ['success' => false, 'error' => 'Session not found'];
        }

        $operations = json_decode($session->operations, true);
        $results = [];
        $errors = [];

        foreach ($operations as $operation) {
            // If selective execution, skip non-selected books
            if (!empty($selectedBookIds) && !in_array($operation['book_id'] ?? null, $selectedBookIds)) {
                continue;
            }

            try {
                $result = $this->executeOperation($operation);
                $results[] = $result;
            } catch (\Exception $e) {
                $errors[] = [
                    'operation' => $operation,
                    'error' => $e->getMessage(),
                ];
                Log::error('Operation execution failed', [
                    'session_id' => $sessionId,
                    'operation' => $operation,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update session status
        DB::table('ai_assistant_sessions')
            ->where('id', $sessionId)
            ->update([
                'status' => empty($errors) ? 'completed' : 'partially_completed',
                'executed_at' => now(),
                'execution_results' => json_encode([
                    'results' => $results,
                    'errors' => $errors,
                ]),
                'updated_at' => now(),
            ]);

        return [
            'success' => empty($errors),
            'executed_count' => count($results),
            'error_count' => count($errors),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    protected function executeOperation(array $operation): array
    {
        return match ($operation['type']) {
            'update' => $this->executeUpdate($operation),
            'delete' => $this->executeDelete($operation),
            'create' => $this->executeCreate($operation),
            default => ['success' => false, 'error' => 'Unknown operation type'],
        };
    }

    protected function executeUpdate(array $operation): array
    {
        $bookId = $operation['book_id'];
        $changes = $operation['changes'];

        DB::table('books')
            ->where('id', $bookId)
            ->update(array_merge($changes, ['updated_at' => now()]));

        return [
            'success' => true,
            'operation' => 'update',
            'book_id' => $bookId,
            'changes' => $changes,
        ];
    }

    protected function executeDelete(array $operation): array
    {
        $bookId = $operation['book_id'];

        // Delete file if requested
        if ($operation['delete_files'] && !empty($operation['file_path'])) {
            $filePath = $operation['file_path'];
            if (Storage::disk('books')->exists($filePath)) {
                Storage::disk('books')->delete($filePath);
            }
        }

        // Delete database record
        DB::table('books')->where('id', $bookId)->delete();

        return [
            'success' => true,
            'operation' => 'delete',
            'book_id' => $bookId,
            'deleted_files' => $operation['delete_files'],
        ];
    }

    protected function executeCreate(array $operation): array
    {
        $metadata = $operation['metadata'];

        $bookId = DB::table('books')->insertGetId(array_merge($metadata, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return [
            'success' => true,
            'operation' => 'create',
            'book_id' => $bookId,
        ];
    }

    /**
     * Get provider usage statistics
     */
    public function getUsageStats(): array
    {
        return $this->provider->getUsageStats();
    }
}
