<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contracts\ThemeServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ThemeController extends Controller
{
    public function __construct(
        protected ThemeServiceInterface $themeService
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
            $result = $this->themeService->listThemes($filters, (int) $page, 50, (string) $sort);

            return view('admin.themes.index', [
                'themes' => $result['data'],
                'pagination' => $result['pagination'],
                'search' => $search,
                'sort' => $sort,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin failed to list themes: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to load themes.');
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.themes.create');
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
            'theme_data' => 'required|array',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            $theme = $this->themeService->createTheme(
                Auth::id(),
                $validated
            );

            return redirect()->route('admin.themes.show', $theme['id'])
                ->with('success', 'Theme created successfully!');
        } catch (\Exception $e) {
            Log::error('Admin failed to create theme: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to create theme.')->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        try {
            $theme = $this->themeService->getTheme($id);
            if (!$theme) {
                return redirect()->route('admin.themes.index')->with('error', 'Theme not found.');
            }
            return view('admin.themes.show', ['theme' => $theme]);
        } catch (\Exception $e) {
            return redirect()->route('admin.themes.index')->with('error', 'Failed to load theme.');
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id)
    {
        try {
            $theme = $this->themeService->getTheme($id);
            if (!$theme) {
                return redirect()->route('admin.themes.index')->with('error', 'Theme not found.');
            }
            return view('admin.themes.edit', ['theme' => $theme]);
        } catch (\Exception $e) {
            return redirect()->route('admin.themes.index')->with('error', 'Failed to load theme for editing.');
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
            'theme_data' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            $themeModel = \App\Models\Theme::findOrFail($id);
            $this->themeService->updateTheme($id, $themeModel->user_id, $validated);

            return redirect()->route('admin.themes.show', $id)
                ->with('success', 'Theme updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to update theme.')->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        try {
            $themeModel = \App\Models\Theme::findOrFail($id);
            $this->themeService->deleteTheme($id, $themeModel->user_id);

            return redirect()->route('admin.themes.index')
                ->with('success', 'Theme deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete theme.');
        }
    }
}
