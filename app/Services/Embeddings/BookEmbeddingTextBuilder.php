<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Models\Book;
use App\Models\BookTag;

/**
 * Builds the metadata text embedded for a book (shared by EmbedBookJob, which embeds and
 * caches it, and the recommendation strategies, which re-embed a seed book's current text
 * on the fly to query for similar books without needing to fetch a stored vector back out).
 */
class BookEmbeddingTextBuilder
{
    public function buildMetadataText(Book $book): string
    {
        if (!$book->relationLoaded('authors')) {
            $book->load(['authors', 'series', 'genres']);
        }

        $parts = [
            'Title: ' . $book->title,
            'Authors: ' . $book->authors->pluck('name')->join(', '),
        ];

        if ($book->series->isNotEmpty()) {
            $parts[] = 'Series: ' . $book->series->pluck('name')->join(', ');
        }

        if ($book->genres->isNotEmpty()) {
            $parts[] = 'Genres: ' . $book->genres->pluck('name')->join(', ');
        }

        $systemTags = BookTag::where('book_id', $book->id)->where('scope', 'system')->value('tags') ?? [];
        if (!empty($systemTags)) {
            $parts[] = 'Tags: ' . implode(', ', $systemTags);
        }

        if (!empty($book->description)) {
            $parts[] = 'Description: ' . $book->description;
        }

        return implode("\n", $parts);
    }
}
