<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Author;
use App\Models\Genre;
use App\Models\Series;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AIQueryService
{
    protected AIBookProcessor $aiProcessor;
    protected BookDeletionService $bookDeletionService;

    public function __construct(?string $model = null, bool $paidTier = false)
    {
        $this->aiProcessor = new AIBookProcessor($model, $paidTier);
        $this->bookDeletionService = app(BookDeletionService::class);
    }

    public function processQuery(string $userPrompt, int $userId, ?int $contextQueryId = null, int $contextLimit = 50): array
    {
        try {
            $schemaContext = $this->getSchemaContext();
            $previousContext = null;

            if ($contextQueryId) {
                $previousContext = $this->getPreviousQueryContext($contextQueryId, $contextLimit);
            }

            $systemPrompt = $this->buildSystemPrompt($schemaContext, $userPrompt, $previousContext);
            $response = $this->aiProcessor->complete($systemPrompt);

            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'AI processing failed',
                ];
            }

            $parsedResponse = $this->parseAIResponse($response['data']);

            $entityType = $parsedResponse['entity_type'] ?? 'books';

            $queryRecord = DB::table('ai_queries')->insertGetId([
                'user_id' => $userId,
                'prompt' => $userPrompt,
                'operation_type' => $parsedResponse['operation_type'],
                'generated_queries' => json_encode($parsedResponse['queries']),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_queries')
                ->where('id', $queryRecord)
                ->update([
                    'results' => json_encode([
                        'entity_type' => $entityType,
                        'parent_query_id' => $contextQueryId,
                    ]),
                ]);

            return [
                'success' => true,
                'query_id' => $queryRecord,
                'operation_type' => $parsedResponse['operation_type'],
                'entity_type' => $entityType,
                'queries' => $parsedResponse['queries'],
                'explanation' => $parsedResponse['explanation'],
            ];
        } catch (\Exception $e) {
            Log::error('AI Query processing failed', [
                'prompt' => $userPrompt,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function executeQuery(int $queryId): array
    {
        try {
            $queryRecord = DB::table('ai_queries')->where('id', $queryId)->first();

            if (!$queryRecord) {
                return ['success' => false, 'error' => 'Query not found'];
            }

            $queries = json_decode($queryRecord->generated_queries, true);
            $operationType = $queryRecord->operation_type;

            $existingResults = json_decode($queryRecord->results, true) ?? [];
            $entityType = $existingResults['entity_type'] ?? 'books';

            $results = match ($operationType) {
                'research' => $this->executeResearchQuery($queries),
                'list' => $this->executeListQuery($queries, $entityType),
                'bulk_update' => $this->prepareBulkUpdate($queries, $entityType),
                'parse_update' => $this->prepareParseUpdate($queries, $entityType),
                default => ['success' => false, 'error' => 'Unknown operation type'],
            };

            if ($results['success']) {
                $results['data']['entity_type'] = $entityType;

                // Preserve parent_query_id if it exists
                $parentQueryId = $existingResults['parent_query_id'] ?? null;
                if ($parentQueryId) {
                    $results['data']['parent_query_id'] = $parentQueryId;
                }

                DB::table('ai_queries')
                    ->where('id', $queryId)
                    ->update([
                        'results' => json_encode($results['data']),
                        'executed_at' => now(),
                        'status' => 'executed',
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('ai_queries')
                    ->where('id', $queryId)
                    ->update([
                        'status' => 'failed',
                        'error_message' => $results['error'] ?? 'Unknown error',
                        'updated_at' => now(),
                    ]);
            }

            return $results;
        } catch (\Exception $e) {
            Log::error('Query execution failed', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function applyBulkUpdate(int $queryId, array $selectedIds): array
    {
        try {
            $queryRecord = DB::table('ai_queries')->where('id', $queryId)->first();

            if (!$queryRecord) {
                return ['success' => false, 'error' => 'Query not found'];
            }

            $results = json_decode($queryRecord->results, true);
            $appliedChanges = [];

            foreach ($selectedIds as $itemId) {
                $item = collect($results['preview'])->firstWhere('id', $itemId);

                if (!$item) {
                    continue;
                }

                $applied = $this->applyIndividualUpdate($item);

                if ($applied['success']) {
                    $appliedChanges[] = [
                        'id' => $itemId,
                        'changes' => $applied['changes'],
                        'timestamp' => now()->toISOString(),
                    ];
                }
            }

            DB::table('ai_queries')
                ->where('id', $queryId)
                ->update([
                    'applied_changes' => json_encode($appliedChanges),
                    'status' => 'applied',
                    'updated_at' => now(),
                ]);

            return [
                'success' => true,
                'applied_count' => count($appliedChanges),
                'changes' => $appliedChanges,
            ];
        } catch (\Exception $e) {
            Log::error('Bulk update application failed', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function buildPreviousContextSection(?array $previousContext): string
    {
        if (!$previousContext || empty($previousContext['conversation'])) {
            return "\nNo previous conversation context available.";
        }

        $section = "\n\nCONVERSATION HISTORY:";

        foreach ($previousContext['conversation'] as $index => $query) {
            $section .= "\n\n--- Query " . ($index + 1) . " ---";
            $section .= "\nPrompt: \"{$query['prompt']}\"";
            $section .= "\nOperation: {$query['operation_type']}";
            $section .= "\nEntity Type: {$query['entity_type']}";

            if (!empty($query['generated_plan'])) {
                $section .= "\nExecuted Plan:";
                foreach ($query['generated_plan'] as $q) {
                    $section .= "\n- Type: {$q['type']}";
                    $section .= "\n  Purpose: {$q['purpose']}";
                    if (isset($q['query'])) {
                        $section .= "\n  Query: {$q['query']}";
                    }
                }
            }

            // Always show answers and stats (they are small and contextually important)
            if (!empty($query['stats'])) {
                $section .= "\nResearch Stats/Answer:";
                $section .= "\n" . json_encode($query['stats'], JSON_PRETTY_PRINT);
            } elseif (!empty($query['answer'])) {
                $section .= "\nAnswer: {$query['answer']}";
            }

            // Include a sample of results for Lists/Bulk Updates (Latest query gets more details)
            if (!empty($query['items'])) {
                // For older queries, show very simplified list or just count
                if ($index === count($previousContext['conversation']) - 1) {
                    $sampleSize = min(50, count($query['items']));
                    $sample = array_slice($query['items'], 0, $sampleSize);
                    $section .= "\nResults ({$sampleSize} of " . count($query['items']) . " items):";
                    $section .= "\n" . json_encode($sample, JSON_PRETTY_PRINT);
                } else {
                    $section .= "\nResults: " . count($query['items']) . " items found (details truncated for brevity).";
                }
            } elseif (!empty($query['preview'])) {
                if ($index === count($previousContext['conversation']) - 1) {
                    $sampleSize = min(50, count($query['preview']));
                    $sample = array_slice($query['preview'], 0, $sampleSize);
                    $section .= "\nPreview Items ({$sampleSize} of " . count($query['preview']) . " items):";
                    $section .= "\n" . json_encode($sample, JSON_PRETTY_PRINT);
                } else {
                    $section .= "\nPreview: " . count($query['preview']) . " items proposed (details truncated).";
                }
            }
        }

        return $section;
    }

    protected function getPreviousQueryContext(int $queryId, int $contextLimit = 50): ?array
    {
        try {
            // Load all queries in this conversation
            // Only include queries explicitly linked via parent_query_id
            $rootId = $queryId;
            $seenIds = [$rootId];

            // Traverse up to find true root
            while (true) {
                $q = DB::table('ai_queries')->where('id', $rootId)->select('results')->first();

                if (!$q) {
                    break;
                }

                $res = json_decode($q->results, true);
                if (!empty($res['parent_query_id'])) {
                    $parentId = $res['parent_query_id'];

                    // Cycle detection
                    if (in_array($parentId, $seenIds)) {
                        Log::warning('Cycle detected in AI query conversation chain', ['query_id' => $queryId, 'trace' => $seenIds]);
                        break;
                    }

                    $rootId = $parentId;
                    $seenIds[] = $rootId;
                } else {
                    // No parent, this is the root
                    break;
                }
            }

            // Now fetch all descendants using iterative BFS to handle any depth
            $allIds = [$rootId];
            $currentLayerIds = [$rootId];

            // Loop until no more children are found (limit 10 deep to prevent infinite loops)
            $depth = 0;
            do {
                // Find direct children of current layer
                $nextLayer = DB::table('ai_queries')
                    ->whereIn('results->parent_query_id', $currentLayerIds)
                    ->pluck('id')
                    ->toArray();

                if (empty($nextLayer)) {
                    break;
                }

                // Filter out already seen IDs to prevent cycles
                $nextLayer = array_diff($nextLayer, $allIds);

                if (empty($nextLayer)) {
                    break;
                }

                $allIds = array_merge($allIds, $nextLayer);
                $currentLayerIds = $nextLayer;
                $depth++;
            } while ($depth < 10);

            $conversationQueries = DB::table('ai_queries')
                ->whereIn('id', $allIds)
                ->orderBy('id', 'asc')
                ->get();

            if ($conversationQueries->isEmpty()) {
                return null;
            }

            $conversation = [];
            foreach ($conversationQueries as $query) {
                $results = json_decode($query->results, true);
                $generatedPlan = json_decode($query->generated_queries, true);

                $contextItem = [
                    'prompt' => $query->prompt,
                    'operation_type' => $query->operation_type,
                    'entity_type' => $results['entity_type'] ?? 'books',
                    'generated_plan' => $generatedPlan ?? [],
                ];

                // Include result data based on operation type
                if ($query->operation_type === 'list') {
                    $contextItem['items'] = $results['items'] ?? $results['books'] ?? [];
                } elseif ($query->operation_type === 'bulk_update') {
                    $contextItem['preview'] = $results['preview'] ?? [];
                } elseif ($query->operation_type === 'parse_update') {
                    $contextItem['preview'] = $results['preview'] ?? [];
                } elseif ($query->operation_type === 'research') {
                    $contextItem['stats'] = $results['stats'] ?? [];
                    $contextItem['answer'] = $results['answer'] ?? '';
                }

                $conversation[] = $contextItem;
            }

            return [
                'conversation' => $conversation,
                'context_limit' => $contextLimit,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to load conversation context', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function buildSystemPrompt(string $schemaContext, string $userPrompt, ?array $previousContext = null): string
    {
        $prompt = <<<PROMPT
You are an AI assistant helping to query and manage a book library database.

DATABASE SCHEMA:
{$schemaContext}

IMPORTANT INSTRUCTIONS:
1. Analyze the user's request and determine if it's:
   - "research": Statistical queries, aggregations, "what/which/how many" questions
   - "list": Finding specific books with actions (returns book list with edit links)
   - "bulk_update": Mass updates to database records or file movements
   - "parse_update": Parse/extract data from fields (like titles) and update database based on parsed results

2. Generate appropriate queries to fulfill the request:
   - Use "sql" type for database queries (valid SQL only, NO Eloquent/PHP code)
   - Use "filesystem_check" type when you need to verify files exist or check directory structure
   - Use "file_operation" type for bulk update file movements
   - Use "parse" type to extract structured data from text fields using regex patterns

3. Return your response ONLY as JSON with this structure:
{
    "operation_type": "research|list|bulk_update|parse_update",
    "entity_type": "books|authors|genres|series|narrators",
    "queries": [
        {
            "type": "sql|filesystem_check|file_operation|parse",
            "query": "the actual SQL query OR filesystem check description OR regex pattern",
            "purpose": "what this query does"
        }
    ],
    "explanation": "Brief explanation of what will be done"
}

IMPORTANT: For "list" and "bulk_update" operations, you MUST specify the entity_type field to indicate what kind of entity is being returned (books, authors, genres, series, narrators).

QUERY TYPES:
- "sql": Valid SQL (SELECT, UPDATE, etc.) - NO Eloquent/PHP code!
- "filesystem_check": Check if files exist, verify directory structure, compare DB paths with actual files
- "file_operation": Describe file movements for bulk updates
- "delete": Mark items for deletion (use for bulk delete operations)
- "parse": Use AI to extract structured data from text fields using natural language understanding

When comparing directory paths to genres, use filesystem_check to actually verify the files exist in the right location!

PARSE QUERY FORMAT:
For "parse" type queries, provide a JSON object describing what to extract:
{
    "source_field": "field_name",
    "extract_instruction": "natural language description of what to extract and how to identify it",
    "extract_fields": ["field1", "field2"],
    "action": {
        "type": "add_series|update_field|...",
        "details": "what to do with extracted data"
    }
}

The AI will analyze the source field content and extract the requested information using natural language understanding, not regex patterns.

EXAMPLES:

User: "give me a list of books that the files do not seem to be in the correct genre"
Response:
{
    "operation_type": "list",
    "entity_type": "books",
    "queries": [
        {
            "type": "sql",
            "query": "SELECT b.id, b.title, b.directory_path, GROUP_CONCAT(DISTINCT g.name) as genres, GROUP_CONCAT(DISTINCT a.name) as authors FROM books b LEFT JOIN book_genre bg ON b.id = bg.book_id LEFT JOIN genres g ON bg.genre_id = g.id LEFT JOIN author_book ab ON b.id = ab.book_id LEFT JOIN authors a ON ab.author_id = a.id GROUP BY b.id, b.title, b.directory_path",
            "purpose": "Get all books with their genres and paths"
        },
        {
            "type": "filesystem_check",
            "query": "check_genre_directory_mismatch",
            "purpose": "Verify which books are in directories that don't match their assigned genre"
        }
    ],
    "explanation": "This will get all books and check if their actual file location matches their database genre"
}

User: "show books in the deathlands series"
Response:
{
    "operation_type": "list",
    "entity_type": "books",
    "queries": [
        {
            "type": "sql",
            "query": "SELECT DISTINCT b.id, b.title, b.directory_path, GROUP_CONCAT(DISTINCT a.name) as authors, GROUP_CONCAT(DISTINCT g.name) as genres, GROUP_CONCAT(DISTINCT CONCAT(s.name, ' #', bs.series_number)) as series FROM books b LEFT JOIN author_book ab ON b.id = ab.book_id LEFT JOIN authors a ON ab.author_id = a.id LEFT JOIN book_genre bg ON b.id = bg.book_id LEFT JOIN genres g ON bg.genre_id = g.id LEFT JOIN book_series bs ON b.id = bs.book_id LEFT JOIN series s ON bs.series_id = s.id WHERE (s.name = 'Deathlands' OR s.name LIKE 'Deathlands %' OR s.name LIKE 'The Deathlands%' OR s.name LIKE '%Deathlands%') GROUP BY b.id, b.title, b.directory_path",
            "purpose": "Find all books in the Deathlands series"
        }
    ],
    "explanation": "This will list all books in the Deathlands series with their authors, genres, and series information including series numbers"
}

User: "list authors without any books"
Response:
{
    "operation_type": "list",
    "entity_type": "authors",
    "queries": [
        {
            "type": "sql",
            "query": "SELECT a.id, a.name FROM authors a LEFT JOIN author_book ab ON a.id = ab.author_id WHERE ab.book_id IS NULL",
            "purpose": "Find all authors with no books"
        }
    ],
    "explanation": "This will list all authors that don't have any books associated with them"
}

CRITICAL FOR BOOK LISTS: When listing books, ALWAYS include these JOINs and GROUP_CONCAT to populate authors, genres, and series:
- LEFT JOIN author_book ab ON b.id = ab.book_id LEFT JOIN authors a ON ab.author_id = a.id
- LEFT JOIN book_genre bg ON b.id = bg.book_id LEFT JOIN genres g ON bg.genre_id = g.id
- LEFT JOIN book_series bs ON b.id = bs.book_id LEFT JOIN series s ON bs.series_id = s.id
- SELECT ... GROUP_CONCAT(DISTINCT a.name) as authors, GROUP_CONCAT(DISTINCT g.name) as genres, GROUP_CONCAT(DISTINCT CONCAT(s.name, ' #', bs.series_number)) as series
- GROUP BY b.id, b.title, b.directory_path (and any other non-aggregated columns)
- The series field should include the series number formatted as "Series Name #Number"

User: "update all files that are by Homer to be stored under the Classic genre"
Response:
{
    "operation_type": "bulk_update",
    "entity_type": "books",
    "queries": [
        {
            "type": "sql",
            "query": "SELECT DISTINCT b.id, b.title, b.directory_path, GROUP_CONCAT(DISTINCT g.name) as genres, GROUP_CONCAT(DISTINCT a.name) as authors FROM books b JOIN author_book ab ON b.id = ab.book_id JOIN authors a ON ab.author_id = a.id LEFT JOIN book_genre bg ON b.id = bg.book_id LEFT JOIN genres g ON bg.genre_id = g.id WHERE a.name = 'Homer' GROUP BY b.id, b.title, b.directory_path",
            "purpose": "Find all books by Homer"
        },
        {
            "type": "file_operation",
            "query": "Move files to Classic genre and update genre to Classic",
            "purpose": "Move files to Classic genre folder"
        }
    ],
    "explanation": "This will move all Homer books to the Classic genre folder and update database records"
}

User: "delete all authors that don't have any books"
Response:
{
    "operation_type": "bulk_update",
    "entity_type": "authors",
    "queries": [
        {
            "type": "sql",
            "query": "SELECT a.id, a.name FROM authors a LEFT JOIN author_book ab ON a.id = ab.author_id WHERE ab.book_id IS NULL",
            "purpose": "Find all authors with no books"
        },
        {
            "type": "delete",
            "query": "delete",
            "purpose": "Mark authors for deletion"
        }
    ],
    "explanation": "This will delete all authors that have no associated books"
}

User: "parse the book titles and identify secondary series like (series2 #num) and update the database to add a second series for each with the name and number as parsed"
Response:
{
    "operation_type": "parse_update",
    "entity_type": "books",
    "queries": [
        {
            "type": "sql",
            "query": "SELECT DISTINCT b.id, b.title, b.directory_path, GROUP_CONCAT(DISTINCT a.name) as authors, GROUP_CONCAT(DISTINCT g.name) as genres, GROUP_CONCAT(DISTINCT CONCAT(s.name, ' #', bs.series_number)) as series FROM books b LEFT JOIN author_book ab ON b.id = ab.book_id LEFT JOIN authors a ON ab.author_id = a.id LEFT JOIN book_genre bg ON b.id = bg.book_id LEFT JOIN genres g ON bg.genre_id = g.id LEFT JOIN book_series bs ON b.id = bs.book_id LEFT JOIN series s ON bs.series_id = s.id GROUP BY b.id, b.title, b.directory_path",
            "purpose": "Get all books with their current series information"
        },
        {
            "type": "parse",
            "query": "{\"source_field\":\"title\",\"extract_instruction\":\"Look for secondary series information in the book title. This is typically found in parentheses and includes a series name and number. Common formats include: (Series Name #1), (Series Name: Book 1), (Series - Part 1), etc. Extract the series name and the series number.\",\"extract_fields\":[\"series_name\",\"series_number\"],\"action\":{\"type\":\"add_series\",\"details\":\"Add the extracted series as an additional series relationship for each book\"}}",
            "purpose": "Use AI to parse titles and extract secondary series names and numbers in various formats"
        }
    ],
    "explanation": "This will use AI to parse book titles and find secondary series information in various formats (like 'The Baronies Trilogy #1' or 'Book 1 of The Skydark Chronicles') and add them as additional series relationships"
}

FOLLOW-UP QUERIES:
The user is asking a follow-up question referencing the PREVIOUS QUERY CONTEXT below.
You MUST:
1.  Analyze the previous specific SQL/Actions used in the "Executed Plan".
2.  MODIFY that previous plan to incorporate the user's new constraints or questions.
3.  DO NOT simply repeat the previous query if the user adds a constraint (e.g., "how many of them are series?" requires ADDING a WHERE/JOIN clause to the previous count query).
4.  Preserve existing JOINs/WHERE clauses unless they conflict with the new request.
5.  If the user asks "how many...", generating a "sql" query with COUNT(*) is usually correct, ensuring you apply the necessary filters.

{$this->buildPreviousContextSection($previousContext)}
USER REQUEST:
{$userPrompt}

Return ONLY the JSON response, no other text.
PROMPT;



        return $prompt;
    }

    protected function getSchemaContext(): string
    {
        return <<<SCHEMA
Tables:
- books: id, title, description, directory_path, release_date, cover_image, publisher_id, needs_review, audio_file_count, created_at, updated_at
- authors: id, name, created_at, updated_at
- author_book: author_id, book_id (IMPORTANT: use author_book NOT book_author)
- genres: id, name, created_at, updated_at
- book_genre: book_id, genre_id
- series: id, name, created_at, updated_at
- book_series: book_id, series_id, series_number (many-to-many pivot table)
- narrators: id, name, created_at, updated_at
- book_narrator: book_id, narrator_id
- users: id, name, email, is_admin, created_at, updated_at
- book_progress: id, book_id, user_id, progress, last_listened, created_at, updated_at

Key Relationships:
- Books have many authors (many-to-many via author_book)
- Books have many genres (many-to-many via book_genre)
- Books have many series (many-to-many via book_series with series_number)
- Books have many narrators (many-to-many via book_narrator)
- Books have directory_path which stores the file system location

File Structure:
- Books are stored in: /storage/books/{genre}/{author}/{title}/
- directory_path typically follows pattern: {genre}/{author}/{title}

CRITICAL: Always use author_book (not book_author) for the authors pivot table!

ENTITY NAME MATCHING STRATEGY:
When searching for entities (series, authors, genres, narrators) by name, use flexible matching to handle variations:

1. For series names, account for common suffixes/prefixes:
   - User says "Deathlands" → Match "Deathlands", "Deathlands Series", "The Deathlands", "Deathlands Trilogy", "Deathlands (GraphicAudio)", etc.
   - Use: WHERE (s.name = 'Name' OR s.name LIKE 'Name %' OR s.name LIKE 'The Name%' OR s.name LIKE '%Name%')

2. For author names, handle variations:
   - "Stephen King" → "Stephen King", "King, Stephen", "Stephen King (Author)", etc.
   - Use: WHERE (a.name = 'Name' OR a.name LIKE '%Name%')

3. For genres and narrators, similar flexible matching:
   - Use: WHERE (g.name = 'Name' OR g.name LIKE '%Name%')

This ensures users don't need to know the exact formatting in the database.
SCHEMA;
    }

    protected function parseAIResponse(string $responseData): array
    {
        $jsonText = preg_replace('/```json\s*/', '', $responseData);
        $jsonText = preg_replace('/```\s*$/', '', $jsonText);
        $jsonText = trim($jsonText);

        $parsed = json_decode($jsonText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse AI response: ' . json_last_error_msg());
        }

        if (!isset($parsed['operation_type']) || !isset($parsed['queries'])) {
            throw new \Exception('Invalid AI response structure');
        }

        return $parsed;
    }

    protected function executeResearchQuery(array $queries): array
    {
        $stats = [];

        foreach ($queries as $queryDef) {
            try {
                $result = $this->executeSingleQuery($queryDef);
                $stats[] = [
                    'purpose' => $queryDef['purpose'],
                    'result' => $result,
                ];
            } catch (\Exception $e) {
                $stats[] = [
                    'purpose' => $queryDef['purpose'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'data' => ['stats' => $stats],
        ];
    }

    protected function executeListQuery(array $queries, string $entityType = 'books'): array
    {
        $items = [];
        $filesystemCheckType = null;

        foreach ($queries as $queryDef) {
            try {
                $result = $this->executeSingleQuery($queryDef);

                if (isset($result['filesystem_check'])) {
                    $filesystemCheckType = $result['filesystem_check'];
                    continue;
                }

                if ($result instanceof \Illuminate\Database\Eloquent\Collection) {
                    foreach ($result as $item) {
                        $items[] = $this->formatEntityForList($item, $entityType);
                    }
                } elseif (is_array($result)) {
                    foreach ($result as $item) {
                        $items[] = $this->formatEntityForList($item, $entityType);
                    }
                }
            } catch (\Exception $e) {
                Log::error('List query execution failed', [
                    'query' => $queryDef,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($filesystemCheckType === 'check_genre_directory_mismatch') {
            $items = array_values(array_filter($items, function ($item) {
                return $this->checkGenreDirectoryMismatch($item);
            }));
        }

        return [
            'success' => true,
            'data' => ['items' => $items],
        ];
    }

    protected function prepareBulkUpdate(array $queries, string $entityType = 'books'): array
    {
        $preview = [];
        $isDeleteOperation = collect($queries)->contains('type', 'delete');

        foreach ($queries as $queryDef) {
            if (in_array($queryDef['type'], ['file_operation', 'delete'])) {
                continue;
            }

            try {
                $result = $this->executeSingleQuery($queryDef);

                if ($result instanceof \Illuminate\Database\Eloquent\Collection) {
                    foreach ($result as $item) {
                        $preview[] = $this->formatItemForBulkUpdate($item, $queries, $entityType, $isDeleteOperation);
                    }
                } elseif (is_array($result)) {
                    foreach ($result as $item) {
                        $preview[] = $this->formatItemForBulkUpdate($item, $queries, $entityType, $isDeleteOperation);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Bulk update preparation failed', [
                    'query' => $queryDef,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => true,
            'data' => [
                'preview' => $preview,
                'queries' => $queries,
                'is_delete' => $isDeleteOperation,
            ],
        ];
    }

    protected function prepareParseUpdate(array $queries, string $entityType = 'books'): array
    {
        $preview = [];
        $parseQuery = null;
        $sqlQuery = null;

        // Separate SQL and parse queries
        foreach ($queries as $query) {
            if ($query['type'] === 'sql') {
                $sqlQuery = $query;
            } elseif ($query['type'] === 'parse') {
                $parseQuery = $query;
            }
        }

        if (!$sqlQuery || !$parseQuery) {
            return [
                'success' => false,
                'error' => 'Parse update requires both SQL and parse queries',
            ];
        }

        try {
            // Execute SQL to get items
            $items = DB::select($sqlQuery['query']);

            // Parse the query JSON
            $parseConfig = json_decode($parseQuery['query'], true);
            if (!$parseConfig) {
                return [
                    'success' => false,
                    'error' => 'Invalid parse configuration',
                ];
            }

            // Process each item
            foreach ($items as $item) {
                $itemArray = is_array($item) ? $item : (array) $item;
                $parsed = $this->parseItemData($itemArray, $parseConfig);

                if ($parsed) {
                    $preview[] = [
                        'id' => $itemArray['id'] ?? null,
                        'title' => $itemArray['title'] ?? 'Unknown',
                        'original_value' => $itemArray[$parseConfig['source_field']] ?? '',
                        'parsed_data' => $parsed,
                        'action' => $parseConfig['action'],
                        'entity_type' => $entityType,
                    ];
                }
            }

            return [
                'success' => true,
                'data' => [
                    'preview' => $preview,
                    'queries' => $queries,
                    'parse_config' => $parseConfig,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Parse update preparation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function parseItemData(array $item, array $parseConfig): ?array
    {
        $sourceField = $parseConfig['source_field'];
        $extractInstruction = $parseConfig['extract_instruction'];
        $extractFields = $parseConfig['extract_fields'];

        if (!isset($item[$sourceField])) {
            return null;
        }

        $sourceValue = $item[$sourceField];

        // Build AI prompt to extract data
        $prompt = <<<PROMPT
Analyze the following text and extract the requested information.

TEXT TO ANALYZE:
"{$sourceValue}"

EXTRACTION TASK:
{$extractInstruction}

FIELDS TO EXTRACT:
{$this->formatFieldsList($extractFields)}

Return ONLY a JSON object with the extracted fields. If you cannot find the information, return an empty object {}.
Do not include any explanation, only the JSON object.

Example response format:
{
    "series_name": "The Baronies Trilogy",
    "series_number": "1"
}
PROMPT;

        try {
            $response = $this->aiProcessor->complete($prompt);

            if (!$response['success']) {
                Log::warning('AI parsing failed for item', [
                    'item_id' => $item['id'] ?? null,
                    'source_value' => $sourceValue,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);
                return null;
            }

            // Parse AI response
            $responseText = trim($response['data']);

            // Remove markdown code blocks if present
            $responseText = preg_replace('/```json\s*/', '', $responseText);
            $responseText = preg_replace('/```\s*$/', '', $responseText);
            $responseText = trim($responseText);

            $parsed = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to parse AI extraction response', [
                    'item_id' => $item['id'] ?? null,
                    'source_value' => $sourceValue,
                    'response' => $responseText,
                    'error' => json_last_error_msg(),
                ]);
                return null;
            }

            // Validate that we got the expected fields
            $hasData = false;
            foreach ($extractFields as $field) {
                if (!empty($parsed[$field])) {
                    $hasData = true;
                    break;
                }
            }

            return $hasData ? $parsed : null;
        } catch (\Exception $e) {
            Log::error('Exception during AI parsing', [
                'item_id' => $item['id'] ?? null,
                'source_value' => $sourceValue,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function formatFieldsList(array $fields): string
    {
        return implode("\n", array_map(fn ($field) => "- {$field}", $fields));
    }

    protected function executeSingleQuery(array $queryDef)
    {
        if ($queryDef['type'] === 'sql') {
            return DB::select($queryDef['query']);
        }

        if ($queryDef['type'] === 'filesystem_check') {
            return $this->executeFilesystemCheck($queryDef);
        }

        throw new \Exception('Unsupported query type: ' . $queryDef['type']);
    }

    protected function executeFilesystemCheck(array $queryDef): array
    {
        $checkType = $queryDef['query'];

        return [
            'filesystem_check' => $checkType,
            'purpose' => $queryDef['purpose'] ?? 'Filesystem verification',
        ];
    }

    protected function checkGenreDirectoryMismatch($book): bool
    {
        $bookArray = is_array($book) ? $book : (array) $book;

        if (!isset($bookArray['directory_path']) || empty($bookArray['directory_path'])) {
            return false;
        }

        $disk = Storage::disk('books');

        if (!$disk->exists($bookArray['directory_path'])) {
            return true;
        }

        $pathParts = explode('/', trim($bookArray['directory_path'], '/'));
        $directoryGenre = $pathParts[0] ?? null;

        if (!$directoryGenre) {
            return false;
        }

        $genres = isset($bookArray['genres']) ? (is_string($bookArray['genres']) ? explode(',', $bookArray['genres']) : $bookArray['genres']) : [];

        foreach ($genres as $genre) {
            $normalizedGenre = strtolower(trim($genre));
            $normalizedDirGenre = strtolower($directoryGenre);

            if ($normalizedGenre === $normalizedDirGenre) {
                return false;
            }
        }

        return true;
    }

    protected function formatEntityForList($entity, string $entityType): array
    {
        $entityArray = is_array($entity) ? $entity : (array) $entity;

        return match ($entityType) {
            'authors' => $this->formatAuthorForList($entityArray),
            'genres' => $this->formatGenreForList($entityArray),
            'series' => $this->formatSeriesForList($entityArray),
            'narrators' => $this->formatNarratorForList($entityArray),
            default => $this->formatBookForList($entityArray),
        };
    }

    protected function formatBookForList($book): array
    {
        if (is_array($book)) {
            return array_merge($book, [
                'edit_url' => route('admin.books.edit', $book['id'] ?? 0),
            ]);
        }

        $bookArray = (array) $book;

        return [
            'id' => $bookArray['id'] ?? null,
            'title' => $bookArray['title'] ?? 'Unknown',
            'authors' => isset($bookArray['authors']) ? explode(',', $bookArray['authors']) : [],
            'genres' => isset($bookArray['genres']) ? explode(',', $bookArray['genres']) : [],
            'series' => $bookArray['series'] ?? null,
            'directory_path' => $bookArray['directory_path'] ?? '',
            'edit_url' => route('admin.books.edit', $bookArray['id'] ?? 0),
        ];
    }

    protected function formatAuthorForList(array $author): array
    {
        return [
            'id' => $author['id'] ?? null,
            'name' => $author['name'] ?? 'Unknown',
            'book_count' => $author['book_count'] ?? $author['bookCount'] ?? 0,
            'edit_url' => route('admin.authors.edit', $author['id'] ?? 0),
        ];
    }

    protected function formatGenreForList(array $genre): array
    {
        return [
            'id' => $genre['id'] ?? null,
            'name' => $genre['name'] ?? 'Unknown',
            'book_count' => $genre['book_count'] ?? $genre['bookCount'] ?? 0,
            'edit_url' => route('admin.genres.edit', $genre['id'] ?? 0),
        ];
    }

    protected function formatSeriesForList(array $series): array
    {
        return [
            'id' => $series['id'] ?? null,
            'name' => $series['name'] ?? 'Unknown',
            'book_count' => $series['book_count'] ?? $series['bookCount'] ?? 0,
            'edit_url' => route('admin.series.edit', $series['id'] ?? 0),
        ];
    }

    protected function formatNarratorForList(array $narrator): array
    {
        return [
            'id' => $narrator['id'] ?? null,
            'name' => $narrator['name'] ?? 'Unknown',
            'book_count' => $narrator['book_count'] ?? $narrator['bookCount'] ?? 0,
            'edit_url' => '#',
        ];
    }

    protected function formatItemForBulkUpdate($item, array $queries, string $entityType = 'books', bool $isDeleteOperation = false): array
    {
        $itemArray = is_array($item) ? $item : (array) $item;

        if ($isDeleteOperation) {
            return [
                'id' => $itemArray['id'] ?? null,
                'entity_type' => $entityType,
                'before' => $itemArray,
                'after' => null,
                'changes' => [
                    'action' => [
                        'from' => 'exists',
                        'to' => 'will be deleted',
                    ],
                ],
                'is_delete' => true,
            ];
        }

        if ($entityType !== 'books') {
            return [
                'id' => $itemArray['id'] ?? null,
                'entity_type' => $entityType,
                'before' => $itemArray,
                'after' => $itemArray,
                'changes' => [],
            ];
        }

        $fileOperations = collect($queries)->firstWhere('type', 'file_operation');

        $before = [
            'id' => $itemArray['id'] ?? null,
            'title' => $itemArray['title'] ?? 'Unknown',
            'directory_path' => $itemArray['directory_path'] ?? '',
            'genres' => isset($itemArray['genres']) ? explode(',', $itemArray['genres']) : [],
        ];

        $after = $before;

        if ($fileOperations) {
            $genreMatch = preg_match('/(?:to|under) (?:the )?(\w+) genre/i', $fileOperations['query'], $matches);
            if ($genreMatch) {
                $newGenre = $matches[1];
                $after['genres'] = [$newGenre];
                $after['directory_path'] = $this->calculateNewPath($itemArray, $newGenre);
            }
        }

        return [
            'id' => $before['id'],
            'before' => $before,
            'after' => $after,
            'changes' => $this->getChanges($before, $after),
        ];
    }

    protected function calculateNewPath($book, string $newGenre): string
    {
        $bookArray = is_array($book) ? $book : (array) $book;

        $authors = isset($bookArray['authors']) ? explode(',', $bookArray['authors']) : [];
        $author = !empty($authors) ? $authors[0] : 'Unknown';
        $title = $bookArray['title'] ?? 'Unknown';

        return "{$newGenre}/{$author}/{$title}";
    }

    protected function getChanges(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $changes[$key] = [
                    'from' => $before[$key],
                    'to' => $value,
                ];
            }
        }

        return $changes;
    }

    protected function applyIndividualUpdate(array $item): array
    {
        try {
            $entityType = $item['entity_type'] ?? 'books';
            $isDelete = $item['is_delete'] ?? false;

            // Handle parse update operations
            if (isset($item['parsed_data']) && isset($item['action'])) {
                return $this->applyParseUpdate($item);
            }

            if ($isDelete) {
                return $this->applyDelete($item['id'], $entityType);
            }

            if ($entityType !== 'books') {
                return [
                    'success' => false,
                    'error' => 'Only books and delete operations are currently supported for bulk updates',
                ];
            }

            $book = Book::findOrFail($item['id']);
            $changes = [];

            if (isset($item['after']['directory_path']) && $item['after']['directory_path'] !== $item['before']['directory_path']) {
                $moveResult = $this->moveBookFiles($book, $item['after']['directory_path']);

                if ($moveResult['success']) {
                    $book->directory_path = $item['after']['directory_path'];
                    $changes['directory_path'] = [
                        'from' => $item['before']['directory_path'],
                        'to' => $item['after']['directory_path'],
                    ];
                }
            }

            if (isset($item['after']['genres']) && $item['after']['genres'] !== $item['before']['genres']) {
                $genreIds = Genre::whereIn('name', $item['after']['genres'])->pluck('id')->toArray();
                $book->genres()->sync($genreIds);
                $changes['genres'] = [
                    'from' => $item['before']['genres'],
                    'to' => $item['after']['genres'],
                ];
            }

            $book->save();

            return [
                'success' => true,
                'changes' => $changes,
            ];
        } catch (\Exception $e) {
            Log::error('Individual update failed', [
                'item' => $item,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function applyDelete(string $id, string $entityType): array
    {
        try {
            $entityName = '';

            switch ($entityType) {
                case 'authors':
                    $author = Author::findOrFail($id);
                    $entityName = $author->name;
                    $author->delete();
                    break;

                case 'genres':
                    $genre = Genre::findOrFail($id);
                    $entityName = $genre->name;
                    $genre->delete();
                    break;

                case 'series':
                    $series = Series::findOrFail($id);
                    $entityName = $series->name;
                    $series->delete();
                    break;

                case 'books':
                    $book = Book::findOrFail($id);
                    $entityName = $book->title;
                    $result = $this->bookDeletionService->moveToTrash($id, true);
                    if (!$result['success']) {
                        return $result;
                    }
                    break;

                default:
                    return [
                        'success' => false,
                        'error' => "Unknown entity type: {$entityType}",
                    ];
            }

            return [
                'success' => true,
                'changes' => [
                    'deleted' => [
                        'from' => $entityName,
                        'to' => 'deleted',
                    ],
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Delete operation failed', [
                'id' => $id,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function applyParseUpdate(array $item): array
    {
        try {
            $parsedData = $item['parsed_data'];
            $action = $item['action'];
            $entityType = $item['entity_type'] ?? 'books';

            // Currently only support books
            if ($entityType !== 'books') {
                return [
                    'success' => false,
                    'error' => 'Parse updates currently only support books',
                ];
            }

            $book = Book::findOrFail($item['id']);
            $changes = [];

            // Handle different action types
            switch ($action['type']) {
                case 'add_series':
                    if (isset($parsedData['series_name']) && isset($parsedData['series_number'])) {
                        $seriesName = trim($parsedData['series_name']);
                        $raw = $parsedData['series_number'];
                        $seriesNumber = is_numeric($raw) && str_contains((string) $raw, '.') ? (float) $raw : (int) $raw;

                        // Find or create the series
                        $series = Series::firstOrCreate(
                            ['name' => $seriesName],
                            ['created_at' => now(), 'updated_at' => now()]
                        );

                        // Check if this relationship already exists
                        $existing = DB::table('book_series')
                            ->where('book_id', $book->id)
                            ->where('series_id', $series->id)
                            ->first();

                        if (!$existing) {
                            // Add the book-series relationship
                            DB::table('book_series')->insert([
                                'book_id' => $book->id,
                                'series_id' => $series->id,
                                'series_number' => $seriesNumber,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            $changes['added_series'] = [
                                'series_name' => $seriesName,
                                'series_number' => $seriesNumber,
                            ];
                        } else {
                            // Update series number if different
                            if ($existing->series_number != $seriesNumber) {
                                DB::table('book_series')
                                    ->where('book_id', $book->id)
                                    ->where('series_id', $series->id)
                                    ->update([
                                        'series_number' => $seriesNumber,
                                        'updated_at' => now(),
                                    ]);

                                $changes['updated_series'] = [
                                    'series_name' => $seriesName,
                                    'series_number' => $seriesNumber,
                                    'previous_number' => $existing->series_number,
                                ];
                            }
                        }
                    }
                    break;

                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown parse action type: ' . $action['type'],
                    ];
            }

            return [
                'success' => true,
                'changes' => $changes,
            ];
        } catch (\Exception $e) {
            Log::error('Parse update application failed', [
                'item' => $item,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function moveBookFiles(Book $book, string $newPath): array
    {
        $oldPath = $book->directory_path;

        try {
            $disk = Storage::disk('books');

            if (!$disk->exists($oldPath)) {
                return [
                    'success' => false,
                    'error' => 'Source directory does not exist',
                ];
            }

            if ($disk->exists($newPath)) {
                return [
                    'success' => false,
                    'error' => 'Destination directory already exists',
                ];
            }

            $files = $disk->allFiles($oldPath);

            $disk->makeDirectory($newPath);

            foreach ($files as $file) {
                $fileName = basename($file);
                $disk->move($file, $newPath . '/' . $fileName);
            }

            $disk->deleteDirectory($oldPath);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('File move failed', [
                'book_id' => $book->id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getQueryHistory(int $userId, int $limit = 10): array
    {
        $queries = DB::table('ai_queries')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $queries->map(function ($query) {
            return [
                'id' => $query->id,
                'prompt' => $query->prompt,
                'operation_type' => $query->operation_type,
                'status' => $query->status,
                'executed_at' => $query->executed_at,
                'created_at' => $query->created_at,
            ];
        })->toArray();
    }
}
