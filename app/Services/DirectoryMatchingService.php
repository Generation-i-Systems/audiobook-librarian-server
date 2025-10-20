<?php

namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Collection;

class DirectoryMatchingService
{
    /**
     * Find potential matches for an orphaned directory
     *
     * @param  string  $orphanedPath  Relative path like "Science Fiction/Author/Series/Book"
     * @param  Collection  $missingBooks  Books with missing directories
     * @return array Top 3 matches with confidence scores
     */
    public function findMatches(string $orphanedPath, Collection $missingBooks): array
    {
        $metadata = $this->extractMetadataFromPath($orphanedPath);
        $matches = [];

        foreach ($missingBooks as $book) {
            $score = $this->calculateMatchScore($metadata, $book);

            // Only include matches with 75% or higher confidence
            if ($score >= 75) {
                $matches[] = [
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'book_path' => $book->directory_path,
                    'authors' => $book->authors->pluck('name')->toArray(),
                    'series' => $book->series->pluck('name')->toArray(),
                    'confidence' => round($score),
                    'reasons' => $this->getMatchReasons($metadata, $book, $score),
                ];
            }
        }

        // Sort by confidence descending
        usort($matches, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        // Return top 3 matches
        return array_slice($matches, 0, 3);
    }

    /**
     * Extract metadata from directory path
     */
    protected function extractMetadataFromPath(string $path): array
    {
        $parts = explode('/', trim($path, '/'));

        return [
            'genre' => $parts[0] ?? null,
            'author' => $parts[1] ?? null,
            'series' => $parts[2] ?? null,
            'book' => $parts[3] ?? $parts[2] ?? null,
            'full_path' => $path,
        ];
    }

    /**
     * Calculate match score between orphaned directory and book
     */
    protected function calculateMatchScore(array $metadata, Book $book): float
    {
        $score = 0.0;

        // Title similarity (most important - 40 points)
        $bookTitle = $this->normalizeTitle($book->title);
        $dirTitle = $this->normalizeTitle($metadata['book']);

        similar_text($bookTitle, $dirTitle, $titlePercent);
        $score += ($titlePercent / 100) * 40;

        // Author match (30 points)
        if ($metadata['author'] && $book->authors->isNotEmpty()) {
            $authorScore = 0;
            foreach ($book->authors as $author) {
                similar_text(
                    strtolower($metadata['author']),
                    strtolower($author->name),
                    $authorPercent
                );
                $authorScore = max($authorScore, $authorPercent);
            }
            $score += ($authorScore / 100) * 30;
        }

        // Series match (20 points)
        if ($metadata['series'] && $book->series->isNotEmpty()) {
            $seriesScore = 0;
            foreach ($book->series as $series) {
                similar_text(
                    strtolower($metadata['series']),
                    strtolower($series->name),
                    $seriesPercent
                );
                $seriesScore = max($seriesScore, $seriesPercent);
            }
            $score += ($seriesScore / 100) * 20;
        }

        // Genre match (10 points)
        if ($metadata['genre'] && $book->genres->isNotEmpty()) {
            foreach ($book->genres as $genre) {
                if (strtolower($metadata['genre']) === strtolower($genre->name)) {
                    $score += 10;
                    break;
                }
            }
        }

        return round($score, 2);
    }

    /**
     * Normalize title for comparison
     */
    protected function normalizeTitle(string $title): string
    {
        // Remove series numbers like "Book 1", "#1", etc.
        $title = preg_replace('/\s+#?\d+\s*$/', '', $title);
        $title = preg_replace('/^\d+\s+/', '', $title);

        // Remove parenthetical content
        $title = preg_replace('/\s*\([^)]*\)\s*/', ' ', $title);

        // Normalize whitespace and case
        $title = preg_replace('/\s+/', ' ', $title);

        return strtolower(trim($title));
    }

    /**
     * Get human-readable reasons for the match
     */
    protected function getMatchReasons(array $metadata, Book $book, float $score): array
    {
        $reasons = [];

        // Title match
        $bookTitle = $this->normalizeTitle($book->title);
        $dirTitle = $this->normalizeTitle($metadata['book']);
        similar_text($bookTitle, $dirTitle, $titlePercent);

        if ($titlePercent >= 80) {
            $reasons[] = sprintf('Title match: %d%%', round($titlePercent));
        }

        // Author match
        if ($metadata['author'] && $book->authors->isNotEmpty()) {
            foreach ($book->authors as $author) {
                similar_text(
                    strtolower($metadata['author']),
                    strtolower($author->name),
                    $authorPercent
                );
                if ($authorPercent >= 80) {
                    $reasons[] = sprintf('Author match: %s (%d%%)', $author->name, round($authorPercent));
                }
            }
        }

        // Series match
        if ($metadata['series'] && $book->series->isNotEmpty()) {
            foreach ($book->series as $series) {
                similar_text(
                    strtolower($metadata['series']),
                    strtolower($series->name),
                    $seriesPercent
                );
                if ($seriesPercent >= 80) {
                    $reasons[] = sprintf('Series match: %s (%d%%)', $series->name, round($seriesPercent));
                }
            }
        }

        // Genre match
        if ($metadata['genre'] && $book->genres->isNotEmpty()) {
            foreach ($book->genres as $genre) {
                if (strtolower($metadata['genre']) === strtolower($genre->name)) {
                    $reasons[] = 'Genre match: ' . $genre->name;
                }
            }
        }

        if (empty($reasons)) {
            $reasons[] = sprintf('Low confidence match (%d%%)', round($score));
        }

        return $reasons;
    }

    /**
     * Batch process all orphaned directories and find matches
     */
    public function matchAll(array $orphanedDirs): array
    {
        $missingBooks = Book::withMissingDirectories()
            ->with(['authors', 'series', 'genres'])
            ->get();

        $results = [];

        foreach ($orphanedDirs as $orphan) {
            $matches = $this->findMatches($orphan['path'], $missingBooks);

            if (!empty($matches)) {
                $results[] = [
                    'orphaned_path' => $orphan['path'],
                    'orphaned_size' => $orphan['size'],
                    'matches' => $matches,
                ];
            }
        }

        return $results;
    }
}
