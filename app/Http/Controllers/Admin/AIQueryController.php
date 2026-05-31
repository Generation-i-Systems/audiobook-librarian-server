<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AIQueryService;
use App\Services\AI\AIToolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Log;

class AIQueryController extends Controller
{
    protected AIQueryService $aiQueryService;
    protected AIToolService $aiToolService;

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('admin');

        $model = config('services.ai.default_model', 'claude-3-5-sonnet');
        $paidTier = config('services.ai.paid_tier', true);
        $this->aiQueryService = new AIQueryService($model, $paidTier);
        $this->aiToolService = new AIToolService('gemini-2.5-flash');
    }

    public function process(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|min:3|max:5000',
            'context_query_id' => 'nullable|integer',
            'context_limit' => 'nullable|integer|min:0|max:1000',
        ]);

        $prompt = $request->input('prompt');

        // Basic sanitization: trim and remove excessive whitespace
        $prompt = trim(preg_replace('/\s+/', ' ', $prompt));

        $contextQueryId = $request->input('context_query_id');
        $contextLimit = $request->input('context_limit', 50);

        $result = $this->aiQueryService->processQuery(
            $prompt,
            Auth::id(),
            $contextQueryId,
            $contextLimit
        );

        if (!$result['success']) {
            return back()->with('error', $result['error']);
        }

        $queryId = $result['query_id'];

        // Auto-execute all query types to generate results/preview
        $executeResult = $this->aiQueryService->executeQuery($queryId);

        if (!$executeResult['success']) {
            return back()->with('error', $executeResult['error']);
        }

        // If this is a follow-up, redirect to the root conversation ID
        // Otherwise redirect to the new query (which becomes the conversation root)
        $conversationId = $contextQueryId ?? $queryId;

        return redirect()->route('admin.ai-query.results', ['queryId' => $conversationId]);
    }

    public function results($queryId)
    {
        // Load the conversation root query
        $rootQuery = ControllerDatabase::table('ai_queries')->where('id', $queryId)->first();

        if (!$rootQuery || $rootQuery->user_id != Auth::id()) {
            Log::warning('Query not found or access denied', ['query_id' => $queryId]);
            abort(404);
        }

        // Load all queries in this conversation (root + all follow-ups)
        // Use simpler approach: get root, then get all follow-ups
        $rootQueries = ControllerDatabase::table('ai_queries')
            ->where('id', $queryId)
            ->where('user_id', Auth::id())
            ->get();

        $followUpQueries = ControllerDatabase::table('ai_queries')
            ->where('user_id', Auth::id())
            ->whereJsonContains('results->parent_query_id', $queryId)
            ->orderBy('id', 'asc')
            ->get();

        $conversationQueries = $rootQueries->merge($followUpQueries);

        // Format conversation for display
        $conversation = [];
        foreach ($conversationQueries as $query) {
            $conversation[] = [
                'id' => $query->id,
                'prompt' => $query->prompt,
                'operation_type' => $query->operation_type,
                'queries' => json_decode($query->generated_queries, true),
                'results' => json_decode($query->results, true),
                'explanation' => $this->getExplanationFromQueries(json_decode($query->generated_queries, true)),
            ];
        }

        // Get the latest query for the main display
        $latestQuery = end($conversation);

        return view('admin.ai-query.results', [
            'conversationId' => $queryId,
            'conversation' => $conversation,
            'latestQuery' => $latestQuery,
            'operationType' => $latestQuery['operation_type'],
            'results' => $latestQuery['results'],
        ]);
    }

    protected function getExplanationFromQueries($queries)
    {
        if (empty($queries)) {
            return 'No explanation available.';
        }

        return collect($queries)->pluck('purpose')->join('; ');
    }

    public function execute(Request $request)
    {
        $request->validate([
            'query_id' => 'required|integer',
        ]);

        $queryId = $request->input('query_id');

        $result = $this->aiQueryService->executeQuery($queryId);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    public function applyBulkUpdate(Request $request)
    {
        $request->validate([
            'query_id' => 'required|integer',
            'selected_ids' => 'required|array',
            'selected_ids.*' => 'integer',
        ]);

        $queryId = $request->input('query_id');
        $selectedIds = $request->input('selected_ids');

        Log::info('Applying bulk update', [
            'query_id' => $queryId,
            'selected_count' => count($selectedIds),
        ]);

        $result = $this->aiQueryService->applyBulkUpdate($queryId, $selectedIds);

        if (!$result['success']) {
            return back()->with('error', $result['error']);
        }

        // Re-execute the query to show remaining items
        $executeResult = $this->aiQueryService->executeQuery($queryId);

        if (!$executeResult['success']) {
            Log::warning('Failed to re-execute query after bulk update', [
                'query_id' => $queryId,
                'error' => $executeResult['error'],
            ]);
        }

        $appliedCount = $result['applied_count'];
        $successMessage = "Successfully applied changes to {$appliedCount} item(s).";

        return redirect()->route('admin.ai-query.results', ['queryId' => $queryId])
            ->with('success', $successMessage);
    }

    public function editQuery(Request $request)
    {
        $request->validate([
            'query_id' => 'required|integer',
            'queries' => 'required|array',
        ]);

        $queryId = $request->input('query_id');
        $queries = $request->input('queries');

        try {
            ControllerDatabase::table('ai_queries')
                ->where('id', $queryId)
                ->where('user_id', Auth::id())
                ->update([
                    'generated_queries' => json_encode($queries),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Query updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Query edit failed', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function history(Request $request)
    {
        $limit = $request->input('limit', 10);
        $history = $this->aiQueryService->getQueryHistory(Auth::id(), $limit);

        return response()->json([
            'success' => true,
            'history' => $history,
        ]);
    }

    public function executeCustom(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|integer',
            'query_id' => 'required|integer',
            'queries' => 'required|array',
            'queries.*.query' => 'required|string',
            'queries.*.type' => 'required|string',
            'queries.*.purpose' => 'required|string',
        ]);

        $queryId = $request->input('query_id');
        $conversationId = $request->input('conversation_id');
        $customQueries = $request->input('queries');

        // Verify ownership
        $queryRecord = ControllerDatabase::table('ai_queries')->where('id', $queryId)->first();
        if (!$queryRecord || $queryRecord->user_id != Auth::id()) {
            abort(403);
        }

        // Update the query record with custom SQL
        ControllerDatabase::table('ai_queries')
            ->where('id', $queryId)
            ->update([
                'generated_queries' => json_encode($customQueries),
                'updated_at' => now(),
            ]);

        // Re-execute the query
        $executeResult = $this->aiQueryService->executeQuery($queryId);

        if (!$executeResult['success']) {
            return back()->with('error', $executeResult['error']);
        }

        return redirect()->route('admin.ai-query.results', ['queryId' => $conversationId])
            ->with('success', 'Custom query executed successfully.');
    }

    public function editPrompt(Request $request)
    {
        $request->validate([
            'query_id' => 'required|integer',
            'conversation_id' => 'required|integer',
            'prompt' => 'required|string|min:3|max:5000',
        ]);

        $prompt = trim(preg_replace('/\s+/', ' ', $request->input('prompt')));

        $queryId = $request->input('query_id');
        $conversationId = $request->input('conversation_id');
        $newPrompt = $prompt;

        // Verify ownership
        $queryRecord = ControllerDatabase::table('ai_queries')->where('id', $queryId)->first();
        if (!$queryRecord || $queryRecord->user_id != Auth::id()) {
            abort(403);
        }

        // Delete all queries after this one in the conversation
        ControllerDatabase::table('ai_queries')
            ->whereRaw("JSON_EXTRACT(results, '$.parent_query_id') = ?", [$conversationId])
            ->where('id', '>', $queryId)
            ->delete();

        // Update the prompt
        ControllerDatabase::table('ai_queries')
            ->where('id', $queryId)
            ->update([
                'prompt' => $newPrompt,
                'updated_at' => now(),
            ]);

        // Get the context (parent query ID if this is a follow-up)
        $results = json_decode($queryRecord->results, true);
        $contextQueryId = $results['parent_query_id'] ?? null;

        // Re-process the query with new prompt
        $result = $this->aiQueryService->processQuery(
            $newPrompt,
            Auth::id(),
            $contextQueryId
        );

        if (!$result['success']) {
            return back()->with('error', $result['error']);
        }

        // Execute the new query
        $newQueryId = $result['query_id'];
        $executeResult = $this->aiQueryService->executeQuery($newQueryId);

        if (!$executeResult['success']) {
            return back()->with('error', $executeResult['error']);
        }

        return redirect()->route('admin.ai-query.results', ['queryId' => $conversationId])
            ->with('success', 'Prompt updated and conversation reset to this point.');
    }

    public function processWithTools(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|min:3',
            'context' => 'nullable|array',
        ]);

        $userQuery = $request->input('prompt');
        $context = $request->input('context', []);

        Log::info('Processing tool-based AI query', [
            'user_id' => Auth::id(),
            'prompt' => $userQuery,
        ]);

        $result = $this->aiToolService->processQuery($userQuery, $context);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 400);
        }

        $queryRecord = ControllerDatabase::table('ai_tool_queries')->insertGetId([
            'user_id' => Auth::id(),
            'prompt' => $userQuery,
            'context' => json_encode($context),
            'response' => $result['response'],
            'conversation_history' => json_encode($result['conversation']),
            'iterations' => $result['iterations'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'query_id' => $queryRecord,
            'response' => $result['response'],
            'iterations' => $result['iterations'],
        ]);
    }

    public function refineItem(Request $request)
    {
        $request->validate([
            'query_id' => 'required|integer',
            'item_id' => 'required|integer',
            'instruction' => 'required|string|min:3',
        ]);

        $queryId = $request->input('query_id');
        $itemId = $request->input('item_id'); // book_id
        $instruction = $request->input('instruction');

        // Load Query
        $queryRecord = ControllerDatabase::table('ai_queries')->where('id', $queryId)->where('user_id', Auth::id())->first();
        if (!$queryRecord) {
            return response()->json(['success' => false, 'error' => 'Query not found'], 404);
        }

        $results = json_decode($queryRecord->results, true);
        $previewItems = $results['results']['preview'] ?? [];

        // Find the item
        $targetItemIndex = null;
        $targetItem = null;
        foreach ($previewItems as $index => $item) {
            if ($item['book_id'] == $itemId) {
                $targetItemIndex = $index;
                $targetItem = $item;
                break;
            }
        }

        if ($targetItemIndex === null) {
            return response()->json(['success' => false, 'error' => 'Item not found in current results'], 404);
        }

        // Construct Refinement Prompt
        $prompt = "Refine the operation for book ID {$itemId} ({$targetItem['title']}).\n" .
            "Current operation preview: " . json_encode($targetItem) . "\n" .
            "User instruction: \"{$instruction}\"\n" .
            "Use the 'preview_and_execute_bulk_operation' tool to generate the updated operation for this SINGLE book only. " .
            "Ensure you include all necessary fields (id, title, etc) in the operation so it can replace the old one.";

        // Call AI Tool Service
        // We create a temporary context-less call or minimal context
        $result = $this->aiToolService->processQuery($prompt, []);

        if (!$result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 500);
        }

        // Extract the tool call payload from the conversation history or iterations
        // This is tricky because AIToolService executes the tool and returns the response.
        // But we want the *preview* data from the tool execution, not the text response.
        // Fortunately, ToolExecutor returns the preview array.
        // We need to find the latest tool output in the iterations.

        $iterations = $result['iterations'];
        $newOperation = null;

        foreach (array_reverse($iterations) as $iteration) {
            if (($iteration['type'] ?? '') === 'tool_execution' && ($iteration['tool'] ?? '') === 'preview_and_execute_bulk_operation') {
                $output = $iteration['output'] ?? [];
                // The tool returns { success, books: [ ... ] }
                if (!empty($output['books'])) {
                    $newOperation = $output['books'][0]; // Should be single book
                    break;
                }
            }
        }

        if (!$newOperation) {
            return response()->json(['success' => false, 'error' => 'AI failed to generate a valid refinement'], 500);
        }

        // Update the item in the results array
        $previewItems[$targetItemIndex] = $newOperation;
        $results['results']['preview'] = $previewItems;

        // Save back to DB
        ControllerDatabase::table('ai_queries')
            ->where('id', $queryId)
            ->update([
                'results' => json_encode($results),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'html' => view('admin.ai-query.partials.bulk-update-row', [
                'item' => $newOperation,
                'isDelete' => $results['is_delete'] ?? false,
                'entityType' => $results['entity_type'] ?? 'books'
            ])->render(),
        ]);
    }

    public function toolQueryHistory(Request $request)
    {
        $limit = $request->input('limit', 20);

        $history = ControllerDatabase::table('ai_tool_queries')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'history' => $history,
        ]);
    }

    public function toolQueryDetails($queryId)
    {
        $query = ControllerDatabase::table('ai_tool_queries')
            ->where('id', $queryId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$query) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'query' => [
                'id' => $query->id,
                'prompt' => $query->prompt,
                'context' => json_decode($query->context, true),
                'response' => $query->response,
                'conversation_history' => json_decode($query->conversation_history, true),
                'iterations' => $query->iterations,
                'created_at' => $query->created_at,
            ],
        ]);
    }
}
