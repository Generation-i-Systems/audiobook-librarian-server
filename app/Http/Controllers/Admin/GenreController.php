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


    public function index(Request $request)
    {
        try {
            $genres = $this->documentStoreService->listGenresWithStats();

            $sort = $request->input('sort', 'name');
            $direction = $request->input('direction', 'asc');

            $allowedSorts = ['name', 'books', 'authors'];
            if (!in_array($sort, $allowedSorts, true)) {
                $sort = 'name';
            }

            $direction = $direction === 'desc' ? 'desc' : 'asc';

            usort($genres, function (array $a, array $b) use ($sort, $direction) {
                $multiplier = $direction === 'desc' ? -1 : 1;

                if ($sort === 'books') {
                    $aValue = $a['bookCount'] ?? 0;
                    $bValue = $b['bookCount'] ?? 0;

                    if ($aValue === $bValue) {
                        return $multiplier * strcmp($a['name'] ?? '', $b['name'] ?? '');
                    }

                    return $multiplier * ($aValue <=> $bValue);
                }

                if ($sort === 'authors') {
                    $aValue = $a['authorCount'] ?? 0;
                    $bValue = $b['authorCount'] ?? 0;

                    if ($aValue === $bValue) {
                        return $multiplier * strcmp($a['name'] ?? '', $b['name'] ?? '');
                    }

                    return $multiplier * ($aValue <=> $bValue);
                }

                return $multiplier * strcmp($a['name'] ?? '', $b['name'] ?? '');
            });

            // Store the current URL as the last viewed list for redirects after edit/update
            session(['last_admin_list_url' => $request->fullUrl()]);

            return view('admin.genres.index', [
                'genres' => $genres,
                'sort' => $sort,
                'direction' => $direction,
            ]);
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
            'emoji' => 'nullable|string|max:16',
            'icon_path' => 'nullable|string|max:255',
        ]);

        try {
            // Check for duplicate
            $genres = $this->documentStoreService->listGenres();
            foreach ($genres as $genre) {
                if (strcasecmp($genre['name'] ?? '', $validated['name']) === 0) {
                    return redirect()->back()->withErrors(['name' => 'A genre with this name already exists.'])->withInput();
                }
            }

            $this->documentStoreService->createGenre([
                'name' => $validated['name'],
                'emoji' => $validated['emoji'] ?: null,
                'icon_path' => $validated['icon_path'] ?: null,
            ]);
            return redirect()->route('admin.genres.index')->with('success', 'Genre created successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to create genre: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to create genre. Please try again.')->withInput();
        }
    }


    public function edit($id, Request $request)
    {
        try {
            $genre = $this->documentStoreService->getGenre($id);
            if (!$genre) {
                abort(404, 'Genre not found');
            }

            $page = max(1, (int) $request->input('page', 1));
            $perPage = 25;

            $booksResult = $this->documentStoreService->listBooks(
                page: $page,
                perPage: $perPage,
                filters: ['genre' => $genre['name']],
                withRelated: true,
                sort: 'title',
                order: 'asc'
            );

            $books = $booksResult['data'] ?? [];
            $total = $booksResult['total'] ?? 0;
            $totalPages = (int) ceil($total / $perPage);

            return view('admin.genres.edit', [
                'genre' => $genre,
                'books' => $books,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'perPage' => $perPage,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load genre for editing: ' . $e->getMessage());
            return redirect()->route('admin.genres.index')->with('error', 'Failed to load genre for editing.');
        }
    }


    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'emoji' => 'nullable|string|max:16',
            'icon_path' => 'nullable|string|max:255',
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

            $this->documentStoreService->updateGenre($id, [
                'name' => $validated['name'],
                'emoji' => $validated['emoji'] ?: null,
                'icon_path' => $validated['icon_path'] ?: null,
            ]);
            return redirect()->route('admin.genres.index')->with('success', 'Genre updated successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to update genre: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to update genre. Please try again.')->withInput();
        }
    }


    public function destroy(Request $request, $id)
    {
        try {
            // Check if genre exists
            $genre = $this->documentStoreService->getGenre($id);
            if (!$genre) {
                abort(404, 'Genre not found');
            }

            $this->documentStoreService->deleteGenre($id);
            $returnUrl = $request->input('return_url', route('admin.genres.index'));
            return redirect()->to($returnUrl)->with('success', 'Genre deleted successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to delete genre: ' . $e->getMessage());
            return redirect()->route('admin.genres.index')->with('error', 'Failed to delete genre. Please try again.');
        }
    }


    public function authors($id, Request $request)
    {
        try {
            $hierarchy = $this->documentStoreService->getGenreAuthorsHierarchy($id);

            if (empty($hierarchy['genre'])) {
                abort(404, 'Genre not found');
            }

            $sort = $request->input('sort', 'name');
            $direction = $request->input('direction', 'asc');

            $allowedSorts = ['name', 'books'];
            if (!in_array($sort, $allowedSorts, true)) {
                $sort = 'name';
            }

            $direction = $direction === 'desc' ? 'desc' : 'asc';

            if (!empty($hierarchy['authors']) && is_array($hierarchy['authors'])) {
                usort($hierarchy['authors'], function (array $a, array $b) use ($sort, $direction) {
                    $multiplier = $direction === 'desc' ? -1 : 1;

                    if ($sort === 'books') {
                        $aValue = $a['bookCount'] ?? 0;
                        $bValue = $b['bookCount'] ?? 0;

                        if ($aValue === $bValue) {
                            return $multiplier * strcmp($a['name'] ?? '', $b['name'] ?? '');
                        }

                        return $multiplier * ($aValue <=> $bValue);
                    }

                    return $multiplier * strcmp($a['name'] ?? '', $b['name'] ?? '');
                });
            }

            return view('admin.genres.authors', [
                'hierarchy' => $hierarchy,
                'sort' => $sort,
                'direction' => $direction,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load genre authors hierarchy: ' . $e->getMessage());

            return redirect()->route('admin.genres.index')->with(
                'error',
                'Failed to load authors for genre. Please try again.'
            );
        }
    }


    public function merge(Request $request)
    {
        $validated = $request->validate([
            'primary_genre_id' => 'required|string',
            'genre_ids' => 'required|array|min:2',
            'genre_ids.*' => 'string',
        ]);

        $primaryGenreId = $validated['primary_genre_id'];
        $genreIds = array_values(array_unique($validated['genre_ids']));

        if (!in_array($primaryGenreId, $genreIds, true)) {
            return redirect()->back()->with(
                'error',
                'Primary genre must be one of the selected genres.'
            );
        }

        $secondaryGenreIds = array_values(array_filter($genreIds, function ($id) use ($primaryGenreId) {
            return $id !== $primaryGenreId;
        }));

        if (empty($secondaryGenreIds)) {
            return redirect()->back()->with(
                'error',
                'Please select at least one genre to merge into the primary.'
            );
        }

        try {
            $affectedBooks = $this->documentStoreService->mergeGenres($primaryGenreId, $secondaryGenreIds);

            return redirect()->route('admin.genres.index')->with(
                'success',
                'Merged ' . count($secondaryGenreIds) . ' genre(s). Updated ' . $affectedBooks . ' book(s).'
            );
        } catch (\Exception $e) {
            Log::error('Failed to merge genres: ' . $e->getMessage());

            return redirect()->back()->with(
                'error',
                'Failed to merge genres. Please try again.'
            );
        }
    }
}
