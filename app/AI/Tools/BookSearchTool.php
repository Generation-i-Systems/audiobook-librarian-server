<?php

namespace App\AI\Tools;

use App\Models\Book;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class BookSearchTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'search_books',
            description: 'Search for books by title, author, or series filtering. Returns a list of books matching the criteria.',
            properties: [
                new ToolProperty(
                    name: 'query',
                    type: PropertyType::STRING,
                    description: 'The search query string (e.g. title or keyword)',
                    required: false
                ),
                new ToolProperty(
                    name: 'series',
                    type: PropertyType::STRING,
                    description: 'Exact or partial series name to filter by',
                    required: false
                ),
                new ToolProperty(
                    name: 'author',
                    type: PropertyType::STRING,
                    description: 'Exact or partial author name to filter by',
                    required: false
                ),
                new ToolProperty(
                    name: 'genre',
                    type: PropertyType::STRING,
                    description: 'Genre to filter by',
                    required: false
                ),
                new ToolProperty(
                    name: 'limit',
                    type: PropertyType::INTEGER,
                    description: 'Maximum number of books to return (default 50)',
                    required: false
                ),
            ]
        );
    }

    public function __invoke(?string $query = null, ?string $series = null, ?string $author = null, ?string $genre = null, int $limit = 50): string
    {
        $booksQuery = Book::query()
            ->with(['authors', 'genres', 'series']);

        if ($query) {
            $booksQuery->where('title', 'LIKE', "%{$query}%");
        }

        if ($series) {
            $booksQuery->whereHas('series', function ($q) use ($series) {
                $q->where('name', 'LIKE', "%{$series}%");
            });
        }

        if ($author) {
            $booksQuery->whereHas('authors', function ($q) use ($author) {
                $q->where('name', 'LIKE', "%{$author}%");
            });
        }

        if ($genre) {
            $booksQuery->whereHas('genres', function ($q) use ($genre) {
                $q->where('name', 'LIKE', "%{$genre}%");
            });
        }

        $results = $booksQuery->limit($limit)->get();

        if ($results->isEmpty()) {
            return "No books found matching criteria.";
        }

        $formatted = $results->map(function ($book) {
            return [
                'id' => $book->id,
                'title' => $book->title,
                'authors' => $book->authors->pluck('name')->implode(', '),
                'series' => $book->series->map(function ($s) {
                    $seriesNumber = data_get($s->pivot, 'series_number');
                    return $seriesNumber ? "{$s->name} #{$seriesNumber}" : $s->name;
                })->implode(', '),
                'genres' => $book->genres->pluck('name')->implode(', '),
                'path' => $book->directory_path,
            ];
        });

        return json_encode([
            'count' => $results->count(),
            'books' => $formatted
        ], JSON_PRETTY_PRINT);
    }
}
