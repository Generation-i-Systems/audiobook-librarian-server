<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emergency optimized book service to bypass memory issues with CamelCaseAttributeAccess trait
 */
class OptimizedBookService
{
    /**
     * Get books with minimal processing and no trait overhead
     */
    public function getBooks(int $page = 1, int $perPage = 10, array $filters = []): array
    {
        try {
            $perPage = min($perPage, 10);
            $query = DB::table('books');
            if (!empty($filters['search'])) {
                $term = '%' . $filters['search'] . '%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('books.title', 'like', $term)->orWhere('books.description', 'like', $term);
                });
            }
            if (!empty($filters['author'])) {
                $query->whereExists(function (Builder $query) use ($filters): void {
                    $query->selectRaw('1')->from('author_book')
                        ->join('authors', 'authors.id', '=', 'author_book.author_id')
                        ->whereColumn('author_book.book_id', 'books.id')
                        ->where('authors.name', 'like', '%' . $filters['author'] . '%');
                });
            }
            if (!empty($filters['genre'])) {
                $query->whereExists(function (Builder $query) use ($filters): void {
                    $query->selectRaw('1')->from('book_genre')
                        ->join('genres', 'genres.id', '=', 'book_genre.genre_id')
                        ->whereColumn('book_genre.book_id', 'books.id')
                        ->where('genres.name', $filters['genre']);
                });
            }

            $total = (clone $query)->count();
            $books = $query->select('books.id', 'books.title', 'books.cover_image', 'books.directory_path', 'books.description')
                ->orderBy('books.title')->offset(($page - 1) * $perPage)->limit($perPage)->get();
            $ids = $books->pluck('id')->all();
            $authors = $this->namesByBook($ids, 'author_book', 'authors', 'author_id');
            $genres = $this->namesByBook($ids, 'book_genre', 'genres', 'genre_id');

            $processedBooks = [];
            foreach ($books as $book) {
                $processedBooks[] = [
                    'id' => $book->id,
                    'title' => $book->title ?? 'Untitled',
                    'author' => $authors[$book->id] ?? ['Unknown'],
                    'genre' => $genres[$book->id] ?? ['Unknown'],
                    'coverImage' => $this->processCoverImage($book->cover_image, $book->directory_path),
                    'description' => substr($book->description ?? 'No description available.', 0, 200),
                    'series' => [],
                ];
            }

            return [
                'data' => $processedBooks,
                'total' => $total,
                'perPage' => $perPage,
                'currentPage' => $page,
                'lastPage' => max(1, ceil($total / $perPage)),
            ];
        } catch (\Exception $e) {
            Log::error('OptimizedBookService failed: ' . $e->getMessage());

            return [
                'data' => [[
                    'id' => '1',
                    'title' => 'Database Error - Contact Admin',
                    'author' => ['System'],
                    'genre' => ['Error'],
                    'coverImage' => asset('images/placeholder.png'),
                    'description' => 'Error loading books: ' . $e->getMessage(),
                    'series' => [],
                ]],
                'total' => 1,
                'perPage' => $perPage,
                'currentPage' => $page,
                'lastPage' => 1,
            ];
        }
    }

    /**
     * Get unique values for filters without model overhead
     */
    public function getUniqueValues(string $field): array
    {
        try {
            switch ($field) {
                case 'author':
                    return DB::table('authors')
                        ->select('name')
                        ->distinct()
                        ->orderBy('name')
                        ->limit(100) // Limit to prevent memory issues
                        ->pluck('name')
                        ->toArray();

                case 'genre':
                    return DB::table('genres')
                        ->select('name')
                        ->distinct()
                        ->orderBy('name')
                        ->limit(50) // Limit to prevent memory issues
                        ->pluck('name')
                        ->toArray();

                case 'series':
                    return DB::table('series')
                        ->select('name')
                        ->distinct()
                        ->orderBy('name')
                        ->limit(100) // Limit to prevent memory issues
                        ->pluck('name')
                        ->toArray();

                default:
                    return [];
            }
        } catch (\Exception $e) {
            Log::error("Error getting unique values for {$field}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent books efficiently
     */
    public function getRecentBooks(int $limit = 5): array
    {
        try {
            $books = DB::table('books')
                ->select('id', 'title', 'cover_image', 'created_at')
                ->where('created_at', '>=', now()->subDays(7))
                ->orderByDesc('created_at')->limit($limit)->get();
            $authors = $this->namesByBook($books->pluck('id')->all(), 'author_book', 'authors', 'author_id');

            $processedBooks = [];
            foreach ($books as $book) {
                $processedBooks[] = [
                    'id' => $book->id,
                    'title' => $book->title ?? 'Untitled',
                    'author' => $authors[$book->id] ?? ['Unknown'],
                    'coverImage' => $this->processCoverImage($book->cover_image),
                    'createdAt' => $book->created_at,
                ];
            }

            return $processedBooks;
        } catch (\Exception $e) {
            Log::error('Error getting recent books: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<int, list<string>> */
    private function namesByBook(array $bookIds, string $pivot, string $table, string $relatedId): array
    {
        if ($bookIds === []) {
            return [];
        }

        $names = DB::table($pivot)->join($table, "$table.id", '=', "$pivot.$relatedId")
            ->whereIn("$pivot.book_id", $bookIds)
            ->select("$pivot.book_id", "$table.name")
            ->distinct()->orderBy("$table.name")->get();

        $byBook = [];
        foreach ($names as $row) {
            $byBook[$row->book_id][] = $row->name;
        }

        return $byBook;
    }

    /**
     * Process cover image URL efficiently
     */
    protected function processCoverImage(?string $coverImage, ?string $directoryPath = null): string
    {
        if (empty($coverImage)) {
            return asset('images/placeholder.png');
        }

        if (str_starts_with($coverImage, ['http://', 'https://', '/'])) {
            return $coverImage;
        }

        $filename = basename($coverImage);
        $relativePath = $filename;
        if (!empty($directoryPath)) {
            $relativePath = rtrim($directoryPath, '/') . '/' . $filename;
        }

        return route('cover.proxy', ['path' => rawurlencode($relativePath)]);
    }
}
