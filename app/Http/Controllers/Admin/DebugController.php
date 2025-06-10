<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class DebugController extends Controller
{
    protected $firestore;

    public function __construct(FirestoreService $firestore)
    {
        $this->firestore = $firestore;
    }

    /**
     * Dump a Firestore document as JSON for debugging.
     */
    public function showDocument($collection, $docId): JsonResponse
    {
        $doc = $this->firestore->getDocument($collection, $docId);
        if ($doc) {
            return response()->json($doc);
        } else {
            return response()->json(['error' => 'Document not found'], 404);
        }
    }

    /**
     * Debug route: middleware info
     */
    public function debugMiddleware(Request $request): JsonResponse
    {
        return response()->json([
            'route_middleware' => $request->route()->gatherMiddleware(),
            'web_group' => \Illuminate\Support\Facades\Route::getMiddlewareGroups()['web'] ?? null,
        ]);
    }

    /**
     * Debug route: authentication/session info
     */
    public function auth(): JsonResponse
    {
        \App\Auth\FirestoreUserProvider::logAuthState();

        return response()->json([
            'auth_user' => Auth::user(),
            'auth_id' => Auth::id(),
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
            'user_class' => Auth::user() ? get_class(Auth::user()) : null,
            'guard' => Auth::getDefaultDriver(),
            'provider' => config('auth.guards.'.Auth::getDefaultDriver().'.provider'),
            'session_driver' => config('session.driver'),
            'session_cookie' => config('session.cookie'),
            'session_cookie_value' => request()->cookie(config('session.cookie')),
        ]);
    }

    /**
     * Debug route: session info
     */
    public function session(): JsonResponse
    {
        return response()->json([
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
        ]);
    }

    /**
     * Debug route: session DB row
     */
    public function sessiondb(): JsonResponse
    {
        $sessionId = session()->getId();
        $row = null;
        try {
            $row = DB::table('sessions')->where('id', $sessionId)->first();
        } catch (\Exception $e) {
            $row = $e->getMessage();
        }

        return response()->json([
            'session_id' => $sessionId,
            'db_row' => $row,
        ]);
    }

    /**
     * Debug route: logout
     */
    public function logout(): JsonResponse
    {
        Auth::logout();
        session()->invalidate();

        return response()->json(['status' => 'logged out']);
    }

    /**
     * Debug route: write to session
     */
    public function sessionWrite(): JsonResponse
    {
        session(['foo' => 'bar']);

        return response()->json([
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
        ]);
    }

    /**
     * Dump all Firestore users (debug)
     */
    public function firestoreUsersDump(): JsonResponse
    {
        $result = FirestoreService::dumpAllUsers();

        return response()->json($result);
    }

    /**
     * Dump all Firestore books (debug)
     */
    public function firestoreBooksDump(): JsonResponse
    {
        $result = FirestoreService::dumpAllBooks();

        return response()->json($result);
    }
}
