<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Skin;
use App\Services\BuiltinSkinService;
use App\Services\SkinValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BuiltinSkinController extends Controller
{
    public function __construct(
        protected BuiltinSkinService $builtinSkins,
        protected SkinValidationService $validationService,
    ) {
    }

    /**
     * Gallery listing of built-in skins.
     */
    public function index()
    {
        $skins = $this->builtinSkins->list();

        return view('gallery.skins.builtin.index', [
            'skins' => $skins,
            'isConfigured' => $this->builtinSkins->isConfigured(),
        ]);
    }

    /**
     * Show a single built-in skin.
     */
    public function show(string $slug)
    {
        $skin = $this->builtinSkins->get($slug);
        if (! $skin) {
            abort(404);
        }

        return view('gallery.skins.builtin.show', ['skin' => $skin]);
    }

    /**
     * Designer view for a built-in skin (admin only — edits files on disk).
     */
    public function designer(string $slug)
    {
        if (! Auth::user()?->is_admin) {
            abort(403, 'Admin access required to edit built-in skins.');
        }

        $skin = $this->builtinSkins->get($slug);
        if (! $skin) {
            abort(404);
        }

        $assets = $this->builtinSkins->listAssets($slug);

        return view('gallery.skins.builtin.designer', [
            'skin' => $skin,
            'assets' => $assets,
        ]);
    }

    /**
     * Save manifest changes for a built-in skin (admin only).
     */
    public function updateManifest(Request $request, string $slug)
    {
        if (! Auth::user()?->is_admin) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $skin = $this->builtinSkins->get($slug);
        if (! $skin) {
            return response()->json(['error' => 'Skin not found'], 404);
        }

        $manifest = $request->input('manifest');
        if (is_string($manifest)) {
            $manifest = json_decode($manifest, true);
        }

        if (! is_array($manifest)) {
            return response()->json(['error' => 'Invalid manifest JSON'], 400);
        }

        try {
            $this->builtinSkins->updateManifest($slug, $manifest);
            $updatedSkin = $this->builtinSkins->get($slug);

            return response()->json(['success' => true, 'skin' => $updatedSkin]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Upload/replace an asset in a built-in skin directory (admin only).
     */
    public function uploadAsset(Request $request, string $slug)
    {
        if (! Auth::user()?->is_admin) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $request->validate([
            'file' => 'required|image|max:2048',
            'path' => 'required|string',
        ]);

        try {
            $relativePath = $this->builtinSkins->addAsset($slug, $request->file('file'), $request->input('path'));
            $url = route('gallery.skins.builtin.asset', ['slug' => $slug, 'path' => $relativePath]);

            return response()->json(['success' => true, 'url' => $url]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * List assets for a built-in skin.
     */
    public function listAssets(string $slug)
    {
        $skin = $this->builtinSkins->get($slug);
        if (! $skin) {
            return response()->json(['error' => 'Skin not found'], 404);
        }

        $assets = $this->builtinSkins->listAssets($slug);

        return response()->json(['success' => true, 'assets' => $assets]);
    }

    /**
     * Serve an asset file from a built-in skin.
     */
    public function serveAsset(string $slug, string $path)
    {
        $fullPath = $this->builtinSkins->resolveAsset($slug, $path);
        if (! $fullPath) {
            abort(404);
        }

        $mime = mime_content_type($fullPath);

        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Fork a built-in skin into a user-owned DB-backed skin.
     */
    public function fork(Request $request, string $slug)
    {
        if (! Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            return redirect()->route('login');
        }

        $skin = $this->builtinSkins->get($slug);
        if (! $skin) {
            abort(404);
        }

        $name = $request->input('name', $skin['name'] . ' (My Copy)');

        try {
            $zipPath = $this->builtinSkins->buildZip($slug);

            $manifest = $skin['manifest'];

            $newSkin = ControllerDatabase::transaction(function () use ($skin, $name, $zipPath, $manifest) {
                $dbSkin = Skin::create([
                    'name' => $name,
                    'author' => $skin['author'],
                    'version' => $skin['version'],
                    'description' => 'Forked from built-in skin: ' . $skin['name'],
                    'user_id' => Auth::id(),
                    'file_path' => 'temp',
                    'file_size' => filesize($zipPath),
                    'manifest' => $manifest,
                    'is_public' => false,
                ]);

                $storedPath = "skins/{$dbSkin->id}/skin.zip";
                $zipContents = file_get_contents($zipPath);
                if ($zipContents !== false) {
                    Storage::disk('local')->put($storedPath, $zipContents);
                }
                $dbSkin->update(['file_path' => $storedPath]);

                // Copy preview if available
                $previewContent = $this->validationService->extractPreview($zipPath);
                if ($previewContent) {
                    Storage::disk('public')->put("skin-previews/{$dbSkin->id}.png", $previewContent);
                    $dbSkin->update(['preview_path' => "skin-previews/{$dbSkin->id}.png"]);
                }

                return $dbSkin->fresh();
            });

            @unlink($zipPath);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'redirect' => route('gallery.skins.designer', $newSkin->id),
                ]);
            }

            return redirect()->route('gallery.skins.designer', $newSkin->id)
                ->with('success', "Forked '{$skin['name']}' — you can now edit your copy.");
        } catch (\Exception $e) {
            Log::error('Failed to fork built-in skin: ' . $e->getMessage());

            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 400);
            }

            return redirect()->back()->with('error', 'Failed to fork skin: ' . $e->getMessage());
        }
    }

    /**
     * Download built-in skin as ZIP.
     */
    public function download(string $slug)
    {
        $skin = $this->builtinSkins->get($slug);
        if (! $skin) {
            abort(404);
        }

        try {
            $zipPath = $this->builtinSkins->buildZip($slug);
            $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $skin['name']) . '.zip';

            return response()->download($zipPath, $filename)->deleteFileAfterSend();
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to generate ZIP.');
        }
    }
}
