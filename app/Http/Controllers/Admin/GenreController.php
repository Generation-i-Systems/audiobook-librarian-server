<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GenreController extends Controller
{
    protected $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    public function index()
    {
        try {
            $genres = $this->documentStoreService->listGenres();
            return view('admin.genres.index', compact('genres'));
        } catch (\Exception $e) {
            Log::error('Failed to list genres: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to load genres. Please try again.');
        }
    }


    public function create()
    {
        return view('admin.genres.create');
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            // Check for duplicate
            $genres = $this->documentStoreService->listGenres();
            foreach ($genres as $genre) {
                if (strcasecmp($genre['name'] ?? '', $validated['name']) === 0) {
                    return redirect()->back()->withErrors(['name' => 'A genre with this name already exists.'])->withInput();
                }
            }

            $this->documentStoreService->createGenre(['name' => $validated['name']]);
            return redirect()->route('admin.genres.index')->with('success', 'Genre created successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to create genre: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to create genre. Please try again.')->withInput();
        }
    }


    public function edit($id)
    {
        try {
            $genre = $this->documentStoreService->getGenre($id);
            if (!$genre) {
                abort(404, 'Genre not found');
            }
            return view('admin.genres.edit', compact('genre'));
        } catch (\Exception $e) {
            Log::error('Failed to load genre for editing: ' . $e->getMessage());
            return redirect()->route('admin.genres.index')->with('error', 'Failed to load genre for editing.');
        }
    }


    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            // Check if genre exists
            $genre = $this->documentStoreService->getGenre($id);
            if (!$genre) {
                abort(404, 'Genre not found');
            }

            // Check for duplicate name
            $genres = $this->documentStoreService->listGenres();
            foreach ($genres as $g) {
                if (strcasecmp($g['name'] ?? '', $validated['name']) === 0 && $g['id'] !== $id) {
                    return redirect()->back()->withErrors(['name' => 'A genre with this name already exists.'])->withInput();
                }
            }

            $this->documentStoreService->updateGenre($id, ['name' => $validated['name']]);
            return redirect()->route('admin.genres.index')->with('success', 'Genre updated successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to update genre: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to update genre. Please try again.')->withInput();
        }
    }


    public function destroy($id)
    {
        try {
            // Check if genre exists
            $genre = $this->documentStoreService->getGenre($id);
            if (!$genre) {
                abort(404, 'Genre not found');
            }

            $this->documentStoreService->deleteGenre($id);
            return redirect()->route('admin.genres.index')->with('success', 'Genre deleted successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to delete genre: ' . $e->getMessage());
            return redirect()->route('admin.genres.index')->with('error', 'Failed to delete genre. Please try again.');
        }
    }


}
