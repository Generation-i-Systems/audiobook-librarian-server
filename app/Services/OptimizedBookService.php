<?php

namespace App\Services;

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
            $perPage = min($perPage, 10); // Hard limit
            $offset = ($page - 1) * $perPage;

            // Base query
            $whereConditions = [];
            $params = [];

            // Apply search filter
            if (!empty($filters['search'])) {
                $whereConditions[] = '(books.title LIKE ? OR books.description LIKE ?)';
                $params[] = '%' . $filters['search'] . '%';
                $params[] = '%' . $filters['search'] . '%';
            }

            // Apply author filter
            if (!empty($filters['author'])) {
                $whereConditions[] = 'EXISTS (
                    SELECT 1 FROM author_book ab
                    JOIN authors a ON ab.author_id = a.id
                    WHERE ab.book_id = books.id AND a.name LIKE ?
                )';
                $params[] = '%' . $filters['author'] . '%';
            }

            // Apply genre filter
            if (!empty($filters['genre'])) {
                $whereConditions[] = 'EXISTS (
                    SELECT 1 FROM book_genre bg
                    JOIN genres g ON bg.genre_id = g.id
                    WHERE bg.book_id = books.id AND g.name = ?
                )';
                $params[] = $filters['genre'];
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            // Get books with minimal fields
            $books = DB::select("
                SELECT books.id, books.title, books.cover_image, books.directory_path, books.description,
                       GROUP_CONCAT(DISTINCT a.name ORDER BY a.name SEPARATOR '|') as authors,
                       GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR '|') as genres
                FROM books
                LEFT JOIN author_book ab ON books.id = ab.book_id
                LEFT JOIN authors a ON ab.author_id = a.id
                LEFT JOIN book_genre bg ON books.id = bg.book_id
                LEFT JOIN genres g ON bg.genre_id = g.id
                {$whereClause}
                GROUP BY books.id, books.title, books.cover_image, books.directory_path, books.description
                ORDER BY books.title ASC
                LIMIT {$perPage} OFFSET {$offset}
            ", $params);

            // Get total count
            $totalQuery = "
                SELECT COUNT(DISTINCT books.id) as total
                FROM books
                LEFT JOIN author_book ab ON books.id = ab.book_id
                LEFT JOIN authors a ON ab.author_id = a.id
                LEFT JOIN book_genre bg ON books.id = bg.book_id
                LEFT JOIN genres g ON bg.genre_id = g.id
                {$whereClause}
            ";
            $total = DB::scalar($totalQuery, $params) ?? 0;

            // Process results efficiently
            $processedBooks = [];
            foreach ($books as $book) {
                $processedBooks[] = [
                    'id' => $book->id,
                    'title' => $book->title ?? 'Untitled',
                    'author' => !empty($book->authors) ? explode('|', $book->authors) : ['Unknown'],
                    'genre' => !empty($book->genres) ? explode('|', $book->genres) : ['Unknown'],
                    'coverImage' => $this->processCoverImage($book->cover_image, $book->directory_path),
                    'description' => substr($book->description ?? 'No description available.', 0, 200),
                    'series' => [], // Skip series for now to save memory
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
                'data' => [
                    [
                        'id' => '1',
                        'title' => 'Database Error - Contact Admin',
                        'author' => ['System'],
                        'genre' => ['Error'],
                        'coverImage' => asset('images/placeholder.png'),
                        'description' => 'Error loading books: ' . $e->getMessage(),
                        'series' => [],
                    ],
                ],
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
            $books = DB::select("
                SELECT books.id, books.title, books.cover_image, books.created_at,
                       GROUP_CONCAT(DISTINCT a.name ORDER BY a.name SEPARATOR '|') as authors
                FROM books
                LEFT JOIN author_book ab ON books.id = ab.book_id
                LEFT JOIN authors a ON ab.author_id = a.id
                WHERE books.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY books.id, books.title, books.cover_image, books.created_at
                ORDER BY books.created_at DESC
                LIMIT {$limit}
            ");

            $processedBooks = [];
            foreach ($books as $book) {
                $processedBooks[] = [
                    'id' => $book->id,
                    'title' => $book->title ?? 'Untitled',
                    'author' => !empty($book->authors) ? explode('|', $book->authors) : ['Unknown'],
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
