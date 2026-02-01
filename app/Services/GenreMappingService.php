<?php

namespace App\Services;

class GenreMappingService
{
    /**
     * Map OpenAudible genres to library directory genres
     * Returns the primary genre for directory organization
     */
    /**
     * Map OpenAudible genres to library directory genres
     */
    public function mapToPrimaryGenre(string $openAudibleGenre): string
    {
        $fullPathLower = mb_strtolower($openAudibleGenre);
        $genreParts = explode(':', $openAudibleGenre);

        // 1. Define Broad Categories that often contain multiple genres
        // We strip these first to see what the "specific" choice is
        $broadCategories = [
            'science fiction & fantasy',
            'sci-fi & fantasy',
            'mystery, thriller & suspense',
            'literature & fiction',
            'biographies & memoirs',
            'religion & spirituality',
            'children\'s audiobooks',
        ];

        $remainder = $fullPathLower;
        foreach ($broadCategories as $broad) {
            if (str_starts_with($fullPathLower, $broad)) {
                $remainder = trim(substr($fullPathLower, strlen($broad)), ' :');
                break;
            }
        }

        // 2. High Priority Keywords (Look in remainder first, then full string)
        $priorityMapping = [
            // Tier 1: Most Specific
            'litrpg' => 'LitRPG',
            'lit rpg' => 'LitRPG',
            'gamelit' => 'LitRPG',
            'science fiction' => 'Science Fiction',
            'sci-fi' => 'Science Fiction',
            'scifi' => 'Science Fiction',
            'fantasy' => 'Fantasy',
            'historical fiction' => 'Historical Fiction',
            'romance' => 'Romance',
            'romantic' => 'Romance',
            'erotica' => 'Romance',
            'kids' => 'Kids',
            'children' => 'Kids',
            'young adult' => 'Kids',
            'juvenile' => 'Kids',
            'christian' => 'Church',
            'church' => 'Church',
            'religion' => 'Religion',
            'spirituality' => 'Religion',

            // Tier 2: General Categories
            'mystery' => 'Mystery',
            'horror' => 'Horror',
            'western' => 'Western',
            'history' => 'History',
            'biography' => 'History',
            'autobiography' => 'History',
            'action' => 'Action',
            'thriller' => 'Action',
            'adventure' => 'Action',
            'suspense' => 'Action',
            'computer' => 'Computer',
            'technology' => 'Computer',
            'tech' => 'Computer',
            'science' => 'Science',
            'classic' => 'Classic',
            'classics' => 'Classic',
            'literature' => 'Classic',

            // Tier 3: Fallbacks
            'non-fiction' => 'Non Fiction',
            'nonfiction' => 'Non Fiction',
            'non fiction' => 'Non Fiction',
            'self-help' => 'Non Fiction',
            'general fiction' => 'General Fiction',
            'fiction' => 'General Fiction',
        ];

        // Search the remainder first (more specific)
        if (!empty($remainder)) {
            foreach ($priorityMapping as $keyword => $mappedGenre) {
                if (str_contains($remainder, $keyword)) {
                    return $mappedGenre;
                }
            }
        }

        // 3. Handle combined categories with ampersand if no specific match found in remainder
        if (count($genreParts) > 1 && str_contains($genreParts[0], '&')) {
            $firstPart = mb_strtolower(trim($genreParts[0]));
            if (str_contains($firstPart, 'science fiction') || str_contains($firstPart, 'sci-fi')) {
                return 'Science Fiction';
            }
            if (str_contains($firstPart, 'mystery') || str_contains($firstPart, 'thriller')) {
                return 'Action';
            }
            if (str_contains($firstPart, 'religion') || str_contains($firstPart, 'spirituality')) {
                return 'Religion';
            }
        }

        // If nothing specific in remainder, search the full string in priority order
        foreach ($priorityMapping as $keyword => $mappedGenre) {
            if (str_contains($fullPathLower, $keyword)) {
                return $mappedGenre;
            }
        }

        return 'Other';
    }

    /**
     * Get all genre names from OpenAudible hierarchy
     * Returns array of genre names to be added to database
     */
    public function extractAllGenres(string $openAudibleGenre): array
    {
        $genreParts = explode(':', $openAudibleGenre);
        $genres = [];

        foreach ($genreParts as $genre) {
            $genre = trim($genre);
            if (!empty($genre)) {
                $genres[] = $genre;
            }
        }

        return $genres;
    }

    /**
     * Get existing library genre directories
     */
    public function getExistingGenres(): array
    {
        $bookRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root'), '/');

        if (!is_dir($bookRoot)) {
            return [];
        }

        $genres = [];
        $directories = glob($bookRoot . '/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $basename = basename($dir);

            // Skip utility directories
            $skipDirs = [
                'OpenAudible',
                'OpenAudibleKyler',
                'misc',
                'utils',
                'otrr',
                'parse',
                'podcasts',
                'other',
                'app',
                'sync',
                'unsorted',
                'books',
            ];

            if (!in_array($basename, $skipDirs)) {
                $genres[] = $basename;
            }
        }

        return $genres;
    }
}
