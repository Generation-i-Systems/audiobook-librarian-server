<?php

namespace App\AI\Tools;

use App\Models\Book;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;

class BookUpdateTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'update_book',
            description: 'Update metadata for a specific book',
            properties: [
                new ToolProperty(
                    name: 'book_id',
                    type: PropertyType::INTEGER,
                    description: 'ID of the book to update',
                    required: true
                ),
                new ToolProperty(
                    name: 'title',
                    type: PropertyType::STRING,
                    description: 'New title of the book',
                    required: false
                ),
                new ArrayProperty(
                    name: 'author_names',
                    description: 'List of author names to replace existing authors',
                    required: false
                ),
                new ArrayProperty(
                    name: 'genre_names',
                    description: 'List of genre names to set',
                    required: false
                ),
                new ToolProperty(
                    name: 'series_name',
                    type: PropertyType::STRING,
                    description: 'New series name',
                    required: false
                ),
                new ToolProperty(
                    name: 'series_number',
                    type: PropertyType::INTEGER,
                    description: 'New series number',
                    required: false
                ),
            ]
        );
    }

    public function __invoke(int $bookId, ?string $title = null, ?array $authorNames = null, ?array $genreNames = null, ?string $seriesName = null, ?int $seriesNumber = null): string
    {
        try {
            $book = Book::findOrFail($bookId);
            $changes = [];

            if ($title && $title !== $book->title) {
                $changes['title'] = ['from' => $book->title, 'to' => $title];
                $book->title = $title;
            }

            if ($authorNames !== null) {
                $authorIds = [];
                foreach ($authorNames as $name) {
                    if (!empty($name)) {
                        $author = \App\Models\Author::firstOrCreate(['name' => trim($name)]);
                        $authorIds[] = $author->id;
                    }
                }
                $book->authors()->sync($authorIds);
                $changes['authors'] = ['to' => implode(', ', $authorNames)];
            }

            if ($genreNames !== null) {
                $genreIds = [];
                foreach ($genreNames as $name) {
                    if (!empty($name)) {
                        $genre = \App\Models\Genre::firstOrCreate(['name' => trim($name)]);
                        $genreIds[] = $genre->id;
                    }
                }
                $book->genres()->sync($genreIds);
                $changes['genres'] = ['to' => implode(', ', $genreNames)];
            }

            if ($seriesName !== null) {
                $series = \App\Models\Series::firstOrCreate(['name' => trim($seriesName)]);
                $number = $seriesNumber ?? 1;
                $book->series()->syncWithoutDetaching([$series->id => ['series_number' => $number]]);
                $changes['series'] = "$seriesName #$number";
            }

            $book->save();

            if (empty($changes)) {
                return "No changes made to Book ID {$bookId}.";
            }

            return "Updated Book ID {$bookId}: " . json_encode($changes);
        } catch (\Exception $e) {
            return "Failed to update Book ID {$bookId}: " . $e->getMessage();
        }
    }
}
