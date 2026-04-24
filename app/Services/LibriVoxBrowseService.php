<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LibriVox\Author;
use App\Models\LibriVox\Book;
use App\Models\LibriVoxSyncLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LibriVoxBrowseService
{
    public function __construct(private readonly LibriVoxApiService $api)
    {
    }

    public function getImportedBooks(string $search, string $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = Book::with(['authors', 'genres']);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('authors', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        match ($sort) {
            'title_asc'  => $query->orderBy('title'),
            'title_desc' => $query->orderByDesc('title'),
            'year_asc'   => $query->orderBy('year'),
            'year_desc'  => $query->orderByDesc('year'),
            'recent_asc' => $query->orderBy('created_at'),
            default      => $query->orderByDesc('created_at'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    public function getTotalImportedCount(): int
    {
        return Book::count();
    }

    /**
     * @return array{genres: array<string>, importedCounts: \Illuminate\Support\Collection, apiCounts: array<string, int>, lastSync: ?LibriVoxSyncLog, synced: bool}
     */
    public function getGenreOverview(string $language): array
    {
        $genres = LibriVoxApiService::GENRES;

        $importedCounts = Book::when($language, fn ($q) => $q->where('language', $language))
            ->join('librivox_book_genre', 'librivox_books.id', '=', 'librivox_book_genre.book_id')
            ->join('librivox_genres', 'librivox_book_genre.genre_id', '=', 'librivox_genres.id')
            ->selectRaw('librivox_genres.name, COUNT(*) as count')
            ->groupBy('librivox_genres.name')
            ->pluck('count', 'name');

        $lastSync = LibriVoxSyncLog::lastCompleted($language ?: 'English');
        $synced   = $lastSync !== null;
        $apiCounts = $synced ? $importedCounts->toArray() : [];

        return compact('genres', 'importedCounts', 'apiCounts', 'lastSync', 'synced');
    }

    /**
     * @return array{books: array<int, array<string, mixed>>, totalCount: int}
     */
    public function getBooksByGenre(string $genre, string $language, int $page, int $limit): array
    {
        if (LibriVoxSyncLog::hasSynced($language ?: 'English')) {
            [$books, $totalCount] = $this->localBooksByGenre($genre, $language, $page, $limit);
        } else {
            $offset     = ($page - 1) * $limit;
            $books      = $this->api->listBooksByGenre($genre, $limit, $offset, $language ?: null);
            $totalCount = $this->api->countBooksByGenre($genre, $language ?: null);
            $this->markLocalIds($books);
        }

        return compact('books', 'totalCount');
    }

    /**
     * @return array{books: array<int, array<string, mixed>>, totalCount: int, authorName: string}
     */
    public function getBooksByAuthor(string $authorId, string $language, int $page, int $limit): array
    {
        if (LibriVoxSyncLog::hasSynced($language ?: 'English')) {
            [$books, $totalCount, $authorName] = $this->localBooksByAuthor($authorId, $language, $page, $limit);
        } else {
            $offset     = ($page - 1) * $limit;
            $books      = $this->api->listBooksByAuthor($authorId, $limit, $offset, $language ?: null);
            $totalCount = count($books) + $offset;
            $authorName = $this->extractAuthorName($books, $authorId);
            $this->markLocalIds($books);
        }

        return compact('books', 'totalCount', 'authorName');
    }

    /**
     * @return array{authors: array<int, array<string, mixed>>, error: ?string}
     */
    public function searchAuthors(string $query): array
    {
        if ($query === '') {
            return ['authors' => [], 'error' => null];
        }

        if (LibriVoxSyncLog::hasSynced('English')) {
            $authors = Author::where('name', 'like', "%{$query}%")
                ->orderBy('name')
                ->limit(30)
                ->get()
                ->map(fn (Author $a) => [
                    'id'         => (string) $a->id,
                    'first_name' => $a->name,
                    'last_name'  => '',
                ])
                ->all();
            return ['authors' => $authors, 'error' => null];
        }

        return ['authors' => $this->api->searchAuthors($query), 'error' => null];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    public function searchBooks(string $query, string $field): array
    {
        if ($query === '') {
            return ['results' => [], 'error' => null];
        }

        $options = $field === 'author' ? ['author' => $query] : [];
        $raw = $this->api->searchBooks($query, $options);

        if ($raw === null) {
            return ['results' => [], 'error' => 'LibriVox API request failed. Please try again.'];
        }

        $existingIds = Book::whereIn('librivox_id', array_column($raw, 'id'))
            ->pluck('id', 'librivox_id');

        $results = array_map(function (array $apiBook) use ($existingIds): array {
            $apiBook['_local_id'] = $existingIds[(string) $apiBook['id']] ?? null;
            return $apiBook;
        }, $raw);

        return ['results' => $results, 'error' => null];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: int} */
    private function localBooksByGenre(string $genre, string $language, int $page, int $limit): array
    {
        $query = Book::with(['authors', 'genres'])
            ->whereHas('genres', fn ($q) => $q->where('name', $genre))
            ->when($language, fn ($q) => $q->where('language', $language))
            ->orderBy('title');

        $total = $query->count();
        $rows  = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return [$this->localBooksToArray($rows), $total];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: int, 2: string} */
    private function localBooksByAuthor(string $authorId, string $language, int $page, int $limit): array
    {
        $query = Book::with(['authors', 'genres'])
            ->whereHas('authors', fn ($q) => $q->where('librivox_authors.id', $authorId))
            ->when($language, fn ($q) => $q->where('language', $language))
            ->orderBy('title');

        $total      = $query->count();
        $rows       = $query->skip(($page - 1) * $limit)->take($limit)->get();
        $firstAuthor = $rows->first()?->authors->firstWhere('id', $authorId);
        $authorName  = $firstAuthor !== null ? $firstAuthor->name : '';

        return [$this->localBooksToArray($rows), $total, $authorName];
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, Book> $books
     * @return array<int, array<string, mixed>>
     */
    private function localBooksToArray(\Illuminate\Database\Eloquent\Collection $books): array
    {
        return $books->map(fn (Book $b) => [
            'id'           => $b->librivox_id,
            'title'        => $b->title,
            'description'  => $b->description,
            'language'     => $b->language,
            'authors'      => $b->authors->map(fn (Author $a) => [
                'id'         => (string) $a->id,
                'first_name' => $a->name,
                'last_name'  => '',
            ])->all(),
            'genres'       => $b->genres->pluck('name')->all(),
            'url_librivox' => ($b->librivox_info ?? [])['url_librivox'] ?? null,
            '_local_id'    => $b->id,
        ])->all();
    }

    private function markLocalIds(array &$books): void
    {
        $existingIds = Book::whereIn('librivox_id', array_column($books, 'id'))
            ->pluck('id', 'librivox_id');

        foreach ($books as &$book) {
            $book['_local_id'] = $existingIds[(string) $book['id']] ?? null;
        }
        unset($book);
    }

    /** @param array<int, array<string, mixed>> $books */
    private function extractAuthorName(array $books, string $authorId): string
    {
        foreach ($books as $book) {
            foreach ($book['authors'] ?? [] as $a) {
                if ((string) $a['id'] === $authorId) {
                    return trim($a['first_name'] . ' ' . $a['last_name']);
                }
            }
        }
        return '';
    }
}
