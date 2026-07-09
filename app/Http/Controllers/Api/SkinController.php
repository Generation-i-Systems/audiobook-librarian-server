<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GalleryProxyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Proxies skin API requests to audiobook-librarian-www, which is the real
 * source of truth for this data (see the extraction plan). Request
 * validation here is kept as a first-pass client-facing 422 responder, in
 * addition to www's own validation — defense in depth, and avoids sending
 * obviously-bad multipart uploads over the wire needlessly. Route names and
 * response shapes are unchanged so existing mobile/web clients see no
 * difference in behavior.
 */
class SkinController extends Controller
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

        $response = $this->gallery->get('/api/v1/skins', $request->only(['search', 'sort', 'page', 'per_page']));

        return response()->json($response->json(), $response->status());
    }

    public function show(int $id): JsonResponse
    {
        $response = $this->gallery->get("/api/v1/skins/{$id}");

        return response()->json($response->json(), $response->status());
    }

    public function download(int $id): Response
    {
        $response = $this->gallery->get("/api/v1/skins/{$id}/download");

        if ($response->failed()) {
            return response($response->body(), $response->status());
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'application/zip',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment',
        ]);
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
            'file' => 'required|file|mimes:zip|max:51200',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->postAsUser(
            '/api/v1/skins/upload',
            $request->only(['name', 'author', 'version', 'description', 'is_public']),
            Auth::user(),
            ['file' => $request->file('file')]
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
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $response = $this->gallery->patchAsUser(
            "/api/v1/skins/{$id}",
            $request->only(['name', 'description', 'is_public']),
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }

    public function destroy(int $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $response = $this->gallery->deleteAsUser("/api/v1/skins/{$id}", Auth::user());

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
            "/api/v1/skins/{$id}/fork",
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
            "/api/v1/skins/{$id}/rate",
            $request->only(['rating', 'comment']),
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }

    public function mySkins(Request $request): JsonResponse
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
            '/api/v1/skins/my-skins',
            $request->only(['page', 'per_page']),
            Auth::user()
        );

        return response()->json($response->json(), $response->status());
    }

    public function getCustomizations(int $id): JsonResponse
    {
        $response = $this->gallery->get("/api/v1/skins/{$id}/customizations");

        return response()->json($response->json(), $response->status());
    }

    public function uploadCustomization(Request $request, int $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:color,image',
            'value' => 'required_if:type,color|nullable|string',
            'image' => 'required_if:type,image|file|image|max:10240',
            'visibility' => 'nullable|in:private,public',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $files = $request->hasFile('image') ? ['image' => $request->file('image')] : [];

        $response = $this->gallery->postAsUser(
            "/api/v1/skins/{$id}/customizations",
            $request->only(['type', 'value', 'visibility']),
            Auth::user(),
            $files
        );

        return response()->json($response->json(), $response->status());
    }
}
