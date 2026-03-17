<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contracts\SkinServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class SkinController extends Controller
{
    public function __construct(
        protected SkinServiceInterface $skinService
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

        $filters = $request->has('search') ? ['search' => $request->get('search')] : [];
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 24);
        $sort = $request->get('sort', 'recent');

        try {
            $result = $this->skinService->listSkins($filters, $page, $perPage, $sort);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $skin = $this->skinService->getSkin($id);

            if (! $skin) {
                return response()->json(['error' => 'Skin not found'], 404);
            }

            return response()->json($skin);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function download(int $id): Response|BinaryFileResponse
    {
        try {
            $skin = $this->skinService->getSkin($id);

            if (! $skin) {
                return response('Skin not found', 404);
            }

            $skinFilePath = $skin['file_path'] ?? $skin['filePath'] ?? null;

            if (! is_string($skinFilePath) || $skinFilePath === '') {
                return response('File not found', 404);
            }

            $filePath = Storage::disk('local')->path($skinFilePath);

            if (! file_exists($filePath)) {
                $legacyFilePath = storage_path('app/' . ltrim($skinFilePath, '/'));
                if (file_exists($legacyFilePath)) {
                    $filePath = $legacyFilePath;
                }
            }

            if (! file_exists($filePath)) {
                $filePath = $this->skinService->buildZip($id);
            }

            if (! file_exists($filePath)) {
                return response('File not found', 404);
            }

            $filePath = $this->repairLegacySkinZipIfNeeded($filePath, $id);

            if ($skinModel = \App\Models\Skin::find($id)) {
                $skinModel->incrementDownloadCount();
            }

            return response()->download($filePath, ($skin['name'] ?? 'skin') . '.zip');
        } catch (\Exception $e) {
            return response($e->getMessage(), 500);
        }
    }

    private function repairLegacySkinZipIfNeeded(string $filePath, int $skinId): string
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return $filePath;
        }

        $entries = [];
        $requiresRepair = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $originalName = $zip->getNameIndex($i);
            $repairedName = $this->repairLegacyZipEntryName($originalName);
            $entries[] = [$originalName, $repairedName, $zip->getFromIndex($i), str_ends_with($originalName, '/')];
            if ($originalName !== $repairedName) {
                $requiresRepair = true;
            }
        }
        $zip->close();

        if (! $requiresRepair) {
            return $filePath;
        }

        $repairedPath = storage_path("app/skins/{$skinId}/skin_repaired_" . time() . '.zip');
        if (! is_dir(dirname($repairedPath))) {
            mkdir(dirname($repairedPath), 0755, true);
        }

        $repairedZip = new ZipArchive();
        if ($repairedZip->open($repairedPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $filePath;
        }

        foreach ($entries as [$originalName, $repairedName, $content, $isDirectory]) {
            if ($isDirectory) {
                $repairedZip->addEmptyDir(rtrim($repairedName, '/'));
                continue;
            }

            $repairedZip->addFromString($repairedName, $content ?: '');
        }

        $repairedZip->close();

        return $repairedPath;
    }

    private function repairLegacyZipEntryName(string $entryName): string
    {
        return match (true) {
            $entryName === 'nifest.json' => 'manifest.json',
            $entryName === 'ADME.md' => 'README.md',
            str_starts_with($entryName, 'sets/') => 'as' . $entryName,
            str_starts_with($entryName, 'eview') => 'pr' . $entryName,
            default => $entryName,
        };
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

        try {
            $skin = $this->skinService->createSkin(
                Auth::id(),
                $request->only(['name', 'author', 'version', 'description', 'is_public']),
                $request->file('file')
            );

            return response()->json($skin, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        try {
            $skin = $this->skinService->updateSkin(
                $id,
                Auth::id(),
                $request->only(['name', 'description', 'is_public'])
            );

            return response()->json($skin);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $this->skinService->deleteSkin($id, Auth::id());

            return response()->json(['message' => 'Skin deleted successfully']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        try {
            $skin = $this->skinService->forkSkin($id, Auth::id(), $request->get('name'));

            return response()->json($skin, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        try {
            $rating = $this->skinService->rateSkin(
                $id,
                Auth::id(),
                $request->get('rating'),
                $request->get('comment')
            );

            return response()->json($rating, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 24);

        try {
            $result = $this->skinService->getMySkins(Auth::id(), $page, $perPage);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getCustomizations(int $id): JsonResponse
    {
        try {
            $userId = Auth::id() ?? 0;
            $result = $this->skinService->getCustomizations($id, $userId);

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        try {
            $customization = $this->skinService->addCustomization(
                $id,
                Auth::id(),
                $request->all()
            );

            return response()->json($customization, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
