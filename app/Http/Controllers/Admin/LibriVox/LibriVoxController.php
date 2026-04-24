<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\LibriVox;

use App\Http\Controllers\Controller;
use App\Services\LibriVoxBrowseService;
use App\Services\LibriVoxSyncService;
use Illuminate\Http\Request;

class LibriVoxController extends Controller
{
    public function __construct(
        private readonly LibriVoxBrowseService $browseService,
        private readonly LibriVoxSyncService $syncService,
    ) {
    }

    public function index(Request $request)
    {
        $search = (string) $request->input('search', '');
        $sort   = (string) $request->input('sort', 'recent_desc');

        $books      = $this->browseService->getImportedBooks($search, $sort);
        $totalCount = $this->browseService->getTotalImportedCount();

        return view('admin.librivox.index', compact('books', 'totalCount', 'sort'));
    }

    public function genres(Request $request)
    {
        $language = (string) $request->input('language', 'English');
        $data     = $this->browseService->getGenreOverview($language);

        return view('admin.librivox.genres', array_merge($data, compact('language')));
    }

    public function genreBooks(Request $request, string $genre)
    {
        $page     = max(1, (int) $request->input('page', 1));
        $limit    = 50;
        $language = (string) $request->input('language', 'English');

        ['books' => $books, 'totalCount' => $totalCount] = $this->browseService->getBooksByGenre($genre, $language, $page, $limit);

        return view('admin.librivox.browse', [
            'books'      => $books,
            'browseBy'   => 'genre',
            'browseKey'  => $genre,
            'label'      => $genre,
            'page'       => $page,
            'hasMore'    => count($books) === $limit,
            'language'   => $language,
            'totalCount' => $totalCount,
            'error'      => null,
        ]);
    }

    public function authors(Request $request)
    {
        $query = (string) $request->input('q', '');
        ['authors' => $authors, 'error' => $error] = $this->browseService->searchAuthors($query);

        return view('admin.librivox.authors', compact('authors', 'query', 'error'));
    }

    public function authorBooks(Request $request, string $authorId)
    {
        $page     = max(1, (int) $request->input('page', 1));
        $limit    = 50;
        $language = (string) $request->input('language', 'English');

        ['books' => $books, 'totalCount' => $totalCount, 'authorName' => $authorName]
            = $this->browseService->getBooksByAuthor($authorId, $language, $page, $limit);

        return view('admin.librivox.browse', [
            'books'      => $books,
            'browseBy'   => 'author',
            'browseKey'  => $authorId,
            'label'      => $authorName ?: "Author #{$authorId}",
            'page'       => $page,
            'hasMore'    => count($books) === $limit,
            'language'   => $language,
            'totalCount' => $totalCount,
            'error'      => null,
        ]);
    }

    public function search(Request $request)
    {
        $query = (string) $request->input('q', '');
        $field = (string) $request->input('field', '');

        ['results' => $results, 'error' => $error] = $this->browseService->searchBooks($query, $field);

        return view('admin.librivox.search', compact('results', 'query', 'error'));
    }

    public function cancelSync(Request $request)
    {
        $language = (string) $request->input('language', 'English');
        ['message' => $message] = $this->syncService->cancel($language);

        return back()->with('success', $message);
    }

    public function triggerSync(Request $request)
    {
        $language = (string) $request->input('language', 'English');
        $full     = $request->boolean('full');

        $this->syncService->trigger($language, $full);

        return back()->with('success', 'Sync started in the background. Counts will update once it completes.');
    }
}
