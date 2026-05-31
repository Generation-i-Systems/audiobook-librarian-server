<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Session;

class DebugController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    /**
     * Dump a document as JSON for debugging.
     */
    public function showDocument($collection, $docId): JsonResponse
    {
        $doc = $this->documentStoreService->getDocument($collection, $docId);
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
        \App\Auth\DocumentUserProvider::logAuthState();

        return response()->json([
            'auth_user' => Auth::user(),
            'auth_id' => Auth::id(),
            'session_id' => session()->getId(),
            'user_class' => Auth::user() ? get_class(Auth::user()) : null,
            'guard' => Auth::getDefaultDriver(),
            'provider' => config('auth.guards.' . Auth::getDefaultDriver() . '.provider'),
            'session_driver' => config('session.driver'),
            'session_cookie' => config('session.cookie'),
            'has_session_cookie' => request()->hasCookie(config('session.cookie')),
        ]);
    }


    /**
     * Debug route: session info
     */
    public function session(): JsonResponse
    {
        return response()->json([
            'session_id' => session()->getId(),
            'session_keys' => array_keys(session()->all()),
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
            $row = ControllerDatabase::table('sessions')->where('id', $sessionId)->first();
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
            'session_keys' => array_keys(session()->all()),
        ]);
    }


    /**
     * Dump all users (debug)
     */
    public function usersDump(): JsonResponse
    {
        $result = $this->documentStoreService->getAllUsers();

        return response()->json($result);
    }


    /**
     * Dump all books (debug)
     */
    public function booksDump(): JsonResponse
    {
        $result = $this->documentStoreService->listBooks(1, 1000);

        return response()->json($result);
    }
}
