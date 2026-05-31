<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\AIAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Log;

class AIAssistantController extends Controller
{
    protected AIAssistantService $assistant;

    public function __construct(AIAssistantService $assistant)
    {
        $this->middleware('auth');
        $this->middleware('admin');
        $this->assistant = $assistant;
    }

    /**
     * Show the AI assistant interface
     */
    public function index()
    {
        $recentSessions = ControllerDatabase::table('ai_assistant_sessions')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return view('admin.ai-assistant.index', [
            'recentSessions' => $recentSessions,
        ]);
    }

    /**
     * Process a new request or continue existing session
     */
    public function process(Request $request)
    {
        $request->validate([
            'message' => 'required|string|min:3|max:1000',
            'session_id' => 'nullable|integer|exists:ai_assistant_sessions,id',
        ]);

        $message = trim($request->input('message'));
        $sessionId = $request->input('session_id');

        // Verify session ownership if provided
        if ($sessionId) {
            $session = ControllerDatabase::table('ai_assistant_sessions')
                ->where('id', $sessionId)
                ->where('user_id', Auth::id())
                ->first();

            if (!$session) {
                return back()->with('error', 'Session not found');
            }
        }

        Log::info('Processing AI assistant request', [
            'user_id' => Auth::id(),
            'message' => $message,
            'session_id' => $sessionId,
        ]);

        try {
            $result = $this->assistant->processRequest($message, $sessionId, Auth::id());

            if (!$result['success']) {
                return back()->with('error', $result['error']);
            }

            return redirect()->route('admin.ai-assistant.session', ['sessionId' => $result['session_id']])
                ->with('success', 'Request processed successfully');
        } catch (\Exception $e) {
            Log::error('AI assistant processing failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to process request: ' . $e->getMessage());
        }
    }

    /**
     * Show a session with preview
     */
    public function session(int $sessionId)
    {
        $session = ControllerDatabase::table('ai_assistant_sessions')
            ->where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$session) {
            abort(404);
        }

        $conversationHistory = json_decode($session->conversation_history ?? '[]', true) ?? [];
        $operations = json_decode($session->operations ?? '[]', true) ?? [];
        $executionResults = json_decode($session->execution_results ?? '[]', true) ?? [];

        return view('admin.ai-assistant.session', [
            'session' => $session,
            'conversation' => $conversationHistory,
            'operations' => $operations,
            'executionResults' => $executionResults,
        ]);
    }

    /**
     * Execute approved operations
     */
    public function execute(Request $request, int $sessionId)
    {
        $request->validate([
            'selected_books' => 'nullable|array',
            'selected_books.*' => 'integer',
        ]);

        // Verify session ownership
        $session = ControllerDatabase::table('ai_assistant_sessions')
            ->where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$session) {
            abort(404);
        }

        if ($session->status !== 'pending_approval') {
            return back()->with('error', 'This session has already been executed or cancelled');
        }

        $selectedBooks = $request->input('selected_books', []);

        Log::info('Executing AI assistant operations', [
            'session_id' => $sessionId,
            'user_id' => Auth::id(),
            'selected_count' => count($selectedBooks),
        ]);

        try {
            $result = $this->assistant->executeOperations($sessionId, $selectedBooks);

            if ($result['success']) {
                $message = "Successfully executed {$result['executed_count']} operation(s)";
                return redirect()->route('admin.ai-assistant.session', ['sessionId' => $sessionId])
                    ->with('success', $message);
            } else {
                $message = "Executed {$result['executed_count']} operation(s) with {$result['error_count']} error(s)";
                return redirect()->route('admin.ai-assistant.session', ['sessionId' => $sessionId])
                    ->with('warning', $message);
            }
        } catch (\Exception $e) {
            Log::error('AI assistant execution failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Execution failed: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a session
     */
    public function cancel(int $sessionId)
    {
        $session = ControllerDatabase::table('ai_assistant_sessions')
            ->where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$session) {
            abort(404);
        }

        ControllerDatabase::table('ai_assistant_sessions')
            ->where('id', $sessionId)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        return redirect()->route('admin.ai-assistant.index')
            ->with('success', 'Session cancelled');
    }

    /**
     * Get usage statistics
     */
    public function stats()
    {
        $stats = $this->assistant->getUsageStats();

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Refine/modify operations in a session
     */
    public function refine(Request $request, int $sessionId)
    {
        $request->validate([
            'message' => 'required|string|min:3|max:1000',
        ]);

        // Same as process but explicitly for refinement
        return $this->process($request->merge(['session_id' => $sessionId]));
    }

    /**
     * Get session history
     */
    public function history(Request $request)
    {
        $limit = $request->input('limit', 20);
        $status = $request->input('status');

        $query = ControllerDatabase::table('ai_assistant_sessions')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($status) {
            $query->where('status', $status);
        }

        $sessions = $query->get();

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
        ]);
    }
}
