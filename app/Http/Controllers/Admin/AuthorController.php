<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Log;

class AuthorController extends Controller
{
    protected $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    public function index(Request $request)
    {
        $search = $request->input('search');
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');
        $perPage = (int) $request->input('perPage', 25);

        $allowedSorts = ['name', 'books'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'name';
        }

        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $authors = $this->documentStoreService->paginateAuthorsWithStats(
            perPage: $perPage,
            search: $search,
            sort: $sort,
            direction: $direction
        );

        // Store the current URL as the last viewed list for redirects after edit/update
        session(['last_admin_list_url' => $request->fullUrl()]);

        $selectedMergeIds = session('selected_merge_author_ids', []);
        $selectedAuthors = $this->documentStoreService->getAuthorsByIdsWithStats($selectedMergeIds);

        return view('admin.authors.index', [
            'authors' => $authors,
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'selectedAuthors' => $selectedAuthors,
            'selectedMergeIds' => $selectedMergeIds,
        ]);
    }


    public function toggleMerge(Request $request)
    {
        $id = (string) $request->input('id');
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'Missing ID'], 400);
        }

        $selectedIds = session('selected_merge_author_ids', []);

        if (in_array($id, $selectedIds, true)) {
            $selectedIds = array_diff($selectedIds, [$id]);
        } else {
            $selectedIds[] = $id;
        }

        session(['selected_merge_author_ids' => array_values($selectedIds)]);

        return response()->json([
            'success' => true,
            'count' => count($selectedIds),
            'selected' => in_array($id, session('selected_merge_author_ids', []), true)
        ]);
    }

    public function clearMerge()
    {
        session()->forget('selected_merge_author_ids');
        return redirect()->route('admin.authors.index')->with('success', 'Merge selection cleared.');
    }


    public function create()
    {
        return view('admin.authors.create');
    }


    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        // Check for duplicate
        foreach ($this->documentStoreService->listAuthors() as $author) {
            if (strcasecmp($author['name'], $request->name) === 0) {
                return redirect()->route('admin.authors.index')->withErrors(['name' => 'Author already exists!']);
            }
        }
        $this->documentStoreService->createAuthor(['name' => $request->name]);

        return redirect()->route('admin.authors.index')->with('success', 'Author created!');
    }


    public function edit($id, Request $request)
    {
        $author = $this->documentStoreService->getAuthor($id);
        if (!$author) {
            abort(404);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 25;

        $booksResult = $this->documentStoreService->listBooks(
            page: $page,
            perPage: $perPage,
            filters: ['author_id' => $id],
            withRelated: true,
            sort: 'title',
            order: 'asc'
        );

        $books = $booksResult['data'] ?? [];
        $total = $booksResult['total'] ?? 0;
        $totalPages = (int) ceil($total / $perPage);

        return view('admin.authors.edit', [
            'author' => $author,
            'books' => $books,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'perPage' => $perPage,
        ]);
    }


    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $author = $this->documentStoreService->getAuthor($id);
        if (!$author) {
            abort(404);
        }
        // Check for duplicate name
        foreach ($this->documentStoreService->listAuthors() as $a) {
            if (strcasecmp($a['name'], $request->name) === 0 && $a['id'] !== $id) {
                return redirect()->route('admin.authors.index')->withErrors(['name' => 'Author already exists!']);
            }
        }
        $this->documentStoreService->updateAuthor($id, ['name' => $request->name]);

        return redirect()->route('admin.authors.index')->with('success', 'Author updated!');
    }


    public function destroy(Request $request, $id)
    {
        $this->documentStoreService->deleteAuthor($id);

        $returnUrl = $request->input('return_url', route('admin.authors.index'));
        return redirect()->to($returnUrl)->with('success', 'Author deleted!');
    }


    /**
     * AJAX endpoint for Tom Select: returns authors matching query string, or all if no query.
     */
    public function ajax(Request $request)
    {
        $q = $request->input('q', '');
        $authors = $this->documentStoreService->listAuthors();
        if ($q) {
            $authors = array_filter($authors, fn ($author) => stripos($author['name'], $q) !== false);
        }
        // Limit and sort
        $authors = array_slice($authors, 0, 20);
        usort($authors, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json(['data' => $authors]);
    }

    public function browse($id, Request $request)
    {
        $genreId = $request->input('genre_id');
        $sort = $request->input('sort', 'series_name');
        $direction = $request->input('direction', 'asc');

        $allowedSorts = ['series_name', 'series_books'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'series_name';
        }

        $direction = $direction === 'desc' ? 'desc' : 'asc';

        try {
            $hierarchy = $this->documentStoreService->getAuthorHierarchy($id, $genreId);

            if (empty($hierarchy['author'])) {
                abort(404);
            }

            if (!empty($hierarchy['series']) && is_array($hierarchy['series'])) {
                usort($hierarchy['series'], function (array $a, array $b) use ($sort, $direction) {
                    $multiplier = $direction === 'desc' ? -1 : 1;

                    if ($sort === 'series_books') {
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

            if (!empty($hierarchy['standaloneBooks']) && is_array($hierarchy['standaloneBooks'])) {
                usort($hierarchy['standaloneBooks'], function (array $a, array $b) use ($direction) {
                    $multiplier = $direction === 'desc' ? -1 : 1;

                    return $multiplier * strcmp($a['title'] ?? '', $b['title'] ?? '');
                });
            }

            return view('admin.authors.browse', [
                'hierarchy' => $hierarchy,
                'sort' => $sort,
                'direction' => $direction,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load author hierarchy: ' . $e->getMessage());

            return redirect()->route('admin.authors.index')->with(
                'error',
                'Failed to load author hierarchy. Please try again.'
            );
        }
    }

    public function merge(Request $request)
    {
        $validated = $request->validate([
            'primary_author_id' => 'required|string',
            'author_ids' => 'required|array|min:2',
            'author_ids.*' => 'string',
        ]);

        $primaryAuthorId = $validated['primary_author_id'];
        $authorIds = array_values(array_unique($validated['author_ids']));

        if (!in_array($primaryAuthorId, $authorIds, true)) {
            return redirect()->back()->with(
                'error',
                'Primary author must be one of the selected authors.'
            );
        }

        $secondaryAuthorIds = array_values(array_filter($authorIds, function ($id) use ($primaryAuthorId) {
            return $id !== $primaryAuthorId;
        }));

        if (empty($secondaryAuthorIds)) {
            return redirect()->back()->with(
                'error',
                'Please select at least one author to merge into the primary.'
            );
        }

        try {
            $affectedBooks = $this->documentStoreService->mergeAuthors($primaryAuthorId, $secondaryAuthorIds);

            // Clear session after successful merge
            session()->forget('selected_merge_author_ids');

            return redirect()->route('admin.authors.index')->with(
                'success',
                'Merged ' . count($secondaryAuthorIds) . ' author(s). Updated ' . $affectedBooks . ' book(s).'
            );
        } catch (\Exception $e) {
            Log::error('Failed to merge authors: ' . $e->getMessage());

            return redirect()->back()->with(
                'error',
                'Failed to merge authors. Please try again.'
            );
        }
    }
}
