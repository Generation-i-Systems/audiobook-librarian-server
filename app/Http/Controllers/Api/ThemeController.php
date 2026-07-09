<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GalleryProxyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Proxies theme API requests to audiobook-librarian-www — see
 * Api\SkinController for the design notes shared by both controllers.
 */
class ThemeController extends Controller
{
    public function __construct(
        protected GalleryProxyClient $gallery
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:255',
            'sort' => 'nullable|in:recent,popular,top_rated',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->get('/api/v1/themes', $request->only(['search', 'sort', 'page', 'per_page']));

        return response()->json($response->json(), $response->status());
    }

    public function show(int $id): JsonResponse
    {
        $response = $this->gallery->get("/api/v1/themes/{$id}");

        return response()->json($response->json(), $response->status());
    }

    public function download(int $id): JsonResponse
    {
        $response = $this->gallery->get("/api/v1/themes/{$id}/download");

        return response()->json($response->json(), $response->status());
    }

    public function store(Request $request): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'description' => 'nullable|string|max:1000',
            'theme_data' => 'required|array',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->postAsUser(
            '/api/v1/themes/upload',
            $request->only(['name', 'author', 'version', 'description', 'theme_data', 'is_public']),
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'theme_data' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->patchAsUser(
            "/api/v1/themes/{$id}",
            $request->only(['name', 'description', 'theme_data', 'is_public']),
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }

    public function destroy(int $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $response = $this->gallery->deleteAsUser("/api/v1/themes/{$id}", Auth::user());

        return response()->json($response->json(), $response->status());
    }

    public function fork(Request $request, int $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->postAsUser(
            "/api/v1/themes/{$id}/fork",
            ['name' => $request->get('name')],
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }

    public function rate(Request $request, int $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->postAsUser(
            "/api/v1/themes/{$id}/rate",
            $request->only(['rating', 'comment']),
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }

    public function myThemes(Request $request): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->getAsUser(
            '/api/v1/themes/my-themes',
            $request->only(['page', 'per_page']),
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }
}
