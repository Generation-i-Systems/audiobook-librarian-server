<?php

namespace App\Services;

class GenreMappingService
{
    /**
     * Map OpenAudible genres to library directory genres
     * Returns the primary genre for directory organization
     */
    public function mapToPrimaryGenre(string $openAudibleGenre): string
    {
        // OpenAudible uses format: "Science Fiction & Fantasy:Fantasy:Dragons & Mythical Creatures"
        // We need to map to existing library directories

        $genreParts = explode(':', $openAudibleGenre);
        
        // Use the second part if it exists (more specific), otherwise use first
        $genreToMap = count($genreParts) > 1 ? trim($genreParts[1]) : trim($genreParts[0]);
        $firstGenre = strtolower($genreToMap);

        // Map to existing library directories
        $mapping = [
            // Science Fiction variants
            'science fiction & fantasy' => 'Science Fiction',
            'science fiction' => 'Science Fiction',
            'sci-fi & fantasy' => 'Science Fiction',
            'sci-fi' => 'Science Fiction',
            'scifi' => 'Science Fiction',

            // Fantasy variants
            'fantasy' => 'Fantasy',
            'epic fantasy' => 'Fantasy',
            'urban fantasy' => 'Fantasy',

            // LitRPG
            'litrpg' => 'LitRPG',
            'lit rpg' => 'LitRPG',

            // Romance
            'romance' => 'Romance',
            'romantic' => 'Romance',

            // History
            'history' => 'History',
            'historical' => 'Historical Fiction',
            'historical fiction' => 'Historical Fiction',

            // Non-Fiction
            'non-fiction' => 'Non Fiction',
            'nonfiction' => 'Non Fiction',
            'non fiction' => 'Non Fiction',
            'memoir' => 'Non Fiction',
            'body, mind & spirit' => 'Non Fiction',
            'self-help' => 'Non Fiction',
            'self help' => 'Non Fiction',
            'reference' => 'Non Fiction',
            'foreign language study' => 'Non Fiction',
            'meteorology' => 'Non Fiction',

            // Biography maps to History
            'biography' => 'History',
            'biography & autobiography' => 'History',
            'autobiography' => 'History',

            // Religion
            'religion' => 'Religion',
            'religious' => 'Religion',
            'christian' => 'Church',
            'spirituality' => 'Religion',

            // Kids
            'children' => 'Kids',
            'kids' => 'Kids',
            'young adult' => 'Kids',
            'juvenile' => 'Kids',

            // Action
            'action' => 'Action',
            'thriller' => 'Action',
            'adventure' => 'Action',
            'suspense' => 'Action',

            // Classic
            'classic' => 'Classic',
            'classics' => 'Classic',
            'literature' => 'Classic',

            // General Fiction (default)
            'fiction' => 'General Fiction',
            'general fiction' => 'General Fiction',
            'contemporary' => 'General Fiction',

            // Garbage genres that should never exist - map to General Fiction
            'copyright' => 'General Fiction',
            'trademarks' => 'General Fiction',
        ];

        // Check for direct match
        if (isset($mapping[$firstGenre])) {
            return $mapping[$firstGenre];
        }

        // Check for partial matches
        foreach ($mapping as $key => $value) {
            if (str_contains($firstGenre, $key) || str_contains($key, $firstGenre)) {
                return $value;
            }
        }

        // Default to General Fiction
        return 'General Fiction';
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
        $bookRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');

        if (!is_dir($bookRoot)) {
            return [];
        }

        $genres = [];
        $directories = glob($bookRoot . '/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $basename = basename($dir);

            // Skip utility directories
            $skipDirs = [
                'OpenAudible', 'OpenAudibleKyler', 'misc', 'utils',
                'otrr', 'parse', 'podcasts', 'other', 'app',
                'sync', 'unsorted', 'books'
            ];

            if (!in_array($basename, $skipDirs)) {
                $genres[] = $basename;
            }
        }

        return $genres;
    }
}
