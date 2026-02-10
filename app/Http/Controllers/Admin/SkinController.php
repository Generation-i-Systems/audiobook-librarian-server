<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contracts\SkinServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SkinController extends Controller
{
    public function __construct(
        protected SkinServiceInterface $skinService
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $search = $request->get('search');
            $sort = $request->get('sort', 'recent');
            $page = $request->get('page', 1);

            $filters = $search ? ['search' => $search] : [];
            // For admin, we might want to see both public and private if we add a filter
            $result = $this->skinService->listSkins($filters, (int) $page, 50, (string) $sort);

            return view('admin.skins.index', [
                'skins' => $result['data'],
                'pagination' => $result['pagination'],
                'search' => $search,
                'sort' => $sort,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin failed to list skins: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to load skins.');
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.skins.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'description' => 'nullable|string|max:1000',
            'file' => 'required|file|mimes:zip|max:51200',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            $skin = $this->skinService->createSkin(
                Auth::id(),
                $validated,
                $request->file('file')
            );

            return redirect()->route('admin.skins.show', $skin['id'])
                ->with('success', 'Skin uploaded successfully!');
        } catch (\Exception $e) {
            Log::error('Admin failed to create skin: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to upload skin.')->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        try {
            $skin = $this->skinService->getSkin($id);
            if (!$skin) {
                return redirect()->route('admin.skins.index')->with('error', 'Skin not found.');
            }
            return view('admin.skins.show', ['skin' => $skin]);
        } catch (\Exception $e) {
            return redirect()->route('admin.skins.index')->with('error', 'Failed to load skin.');
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id)
    {
        try {
            $skin = $this->skinService->getSkin($id);
            if (!$skin) {
                return redirect()->route('admin.skins.index')->with('error', 'Skin not found.');
            }
            return view('admin.skins.edit', ['skin' => $skin]);
        } catch (\Exception $e) {
            return redirect()->route('admin.skins.index')->with('error', 'Failed to load skin for editing.');
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            // Admins can update any skin (need to adjust service or bypass user check if service enforces it)
            // For now, let's assume we might need to update the service or just call it with the skin's original user_id
            $skinModel = \App\Models\Skin::findOrFail($id);
            $this->skinService->updateSkin($id, $skinModel->user_id, $validated);

            return redirect()->route('admin.skins.show', $id)
                ->with('success', 'Skin updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to update skin.')->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        try {
            $skinModel = \App\Models\Skin::findOrFail($id);
            $this->skinService->deleteSkin($id, $skinModel->user_id);

            return redirect()->route('admin.skins.index')
                ->with('success', 'Skin deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete skin.');
        }
    }
}
