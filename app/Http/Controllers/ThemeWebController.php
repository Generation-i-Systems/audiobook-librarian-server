<?php

namespace App\Http\Controllers;

use App\Services\Contracts\ThemeServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ThemeWebController extends Controller
{
    public function __construct(
        protected ThemeServiceInterface $themeService
    ) {
    }

    public function index(Request $request)
    {
        try {
            $search = $request->get('search');
            $sort = $request->get('sort', 'recent');
            $page = $request->get('page', 1);

            $filters = $search ? ['search' => $search] : [];
            $result = $this->themeService->listThemes($filters, (int) $page, 24, (string) $sort);

            return view('gallery.themes.index', [
                'themes' => $result['data'],
                'pagination' => $result['pagination'],
                'search' => $search,
                'sort' => $sort,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list themes: ' . $e->getMessage());

            // @phpstan-ignore-next-line
            return redirect()->back()->with('error', 'Failed to load themes. Please try again.');
        }
    }

    public function show(int $id)
    {
        try {
            $theme = $this->themeService->getTheme($id);

            if (! $theme) {
                return redirect()->route('gallery.themes.index')->with('error', 'Theme not found.');
            }

            return view('gallery.themes.show', ['theme' => $theme]);
        } catch (\Exception $e) {
            Log::error('Failed to show theme: ' . $e->getMessage());

            return redirect()->route('gallery.themes.index')->with('error', 'Failed to load theme.');
        }
    }

    public function create()
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to create themes.');
        }

        return view('gallery.themes.create');
    }

    public function store(Request $request)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to create themes.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'description' => 'nullable|string|max:1000',
            'theme_data' => 'required|json',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            $validated['theme_data'] = json_decode($validated['theme_data'], true);

            $theme = $this->themeService->createTheme(Auth::id(), $validated);

            return redirect()->route('gallery.themes.show', $theme['id'])
                ->with('success', 'Theme created successfully!');
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withErrors(['theme_data' => $e->getMessage()])
                ->withInput();
        } catch (\Exception $e) {
            Log::error('Failed to create theme: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to create theme. Please try again.')
                ->withInput();
        }
    }

    public function edit(int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        try {
            $theme = $this->themeService->getTheme($id);

            if (! $theme) {
                return redirect()->route('gallery.themes.index')->with('error', 'Theme not found.');
            }

            if ($theme['userId'] !== Auth::id() && !Auth::user()->is_admin) {
                return redirect()->route('gallery.themes.show', $id)
                    ->with('error', 'You do not have permission to edit this theme.');
            }

            return view('gallery.themes.edit', ['theme' => $theme]);
        } catch (\Exception $e) {
            Log::error('Failed to load theme for editing: ' . $e->getMessage());

            return redirect()->route('gallery.themes.index')
                ->with('error', 'Failed to load theme.');
        }
    }

    public function update(Request $request, int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'theme_data' => 'nullable|json',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            if (isset($validated['theme_data'])) {
                $validated['theme_data'] = json_decode($validated['theme_data'], true);
            }

            $this->themeService->updateTheme($id, Auth::id(), $validated);

            return redirect()->route('gallery.themes.show', $id)
                ->with('success', 'Theme updated successfully!');
        } catch (\RuntimeException $e) {
            return redirect()->route('gallery.themes.show', $id)
                ->with('error', 'You do not have permission to edit this theme.');
        } catch (\Exception $e) {
            Log::error('Failed to update theme: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to update theme.')
                ->withInput();
        }
    }

    public function destroy(int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        try {
            $this->themeService->deleteTheme($id, Auth::id());

            return redirect()->route('gallery.themes.index')
                ->with('success', 'Theme deleted successfully!');
        } catch (\RuntimeException $e) {
            return redirect()->route('gallery.themes.show', $id)
                ->with('error', 'You do not have permission to delete this theme.');
        } catch (\Exception $e) {
            Log::error('Failed to delete theme: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to delete theme.');
        }
    }

    public function fork(Request $request, int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to fork themes.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $theme = $this->themeService->forkTheme($id, Auth::id(), $validated['name']);

            return redirect()->route('gallery.themes.show', $theme['id'])
                ->with('success', 'Theme forked successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to fork theme: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to fork theme.');
        }
    }

    public function rate(Request $request, int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to rate themes.');
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            $this->themeService->rateTheme($id, Auth::id(), $validated['rating'], $validated['comment'] ?? null);

            return redirect()->route('gallery.themes.show', $id)
                ->with('success', 'Rating submitted successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to rate theme: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to submit rating.');
        }
    }

    public function myThemes(Request $request)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        try {
            $page = $request->get('page', 1);
            $result = $this->themeService->getMyThemes(Auth::id(), $page, 24);

            return view('gallery.themes.my-themes', [
                'themes' => $result['data'],
                'pagination' => $result['pagination'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list my themes: ' . $e->getMessage());

            return redirect()->back()->with('error', 'Failed to load your themes.');
        }
    }
}
