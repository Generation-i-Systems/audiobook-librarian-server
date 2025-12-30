<?php

namespace App\Traits;

trait GenreMapping
{
    /**
     * Get list of valid genres
     */
    public function getValidGenres(): array
    {
        return [
            'Kids',
            'Religion',
            'General Fiction',
            'Church',
            'Science',
            'Historical Fiction',
            'Computer',
            'Classic',
            'History',
            'Non Fiction',
            'Action',
            'LitRPG',
            'Romance',
            'Science Fiction',
            'Other',
            'Fantasy',
        ];
    }

    /**
     * Map AI-extracted genres to valid database genres
     */
    protected function mapToValidGenre(string $aiGenre): string
    {
        $validGenres = $this->getValidGenres();

        // Direct match (case-insensitive)
        foreach ($validGenres as $validGenre) {
            if (strcasecmp($aiGenre, $validGenre) === 0) {
                return $validGenre;
            }
        }

        // Genre mapping rules
        $genreMap = [
            // OpenAudible root categories
            'arts & entertainment' => 'Non Fiction',
            'biographies & memoirs' => 'History',
            'biography & autobiography' => 'History',
            'business & careers' => 'Non Fiction',
            'children\'s audiobooks' => 'Kids',
            'comedy & humor' => 'General Fiction',
            'computers & technology' => 'Computer',
            'education & learning' => 'Non Fiction',
            'erotica' => 'Romance',
            'health & wellness' => 'Non Fiction',
            'home & garden' => 'Non Fiction',
            'literature & fiction' => 'General Fiction',
            'money & finance' => 'Non Fiction',
            'mystery, thriller & suspense' => 'Action',
            'politics & social sciences' => 'Non Fiction',
            'relationships, parenting & personal development' => 'Non Fiction',
            'religion & spirituality' => 'Religion',
            'science & engineering' => 'Science',
            'science fiction & fantasy' => 'Science Fiction',
            'sports & outdoors' => 'Non Fiction',
            'teen & young adult' => 'General Fiction',

            // Science Fiction variations
            'sci-fi' => 'Science Fiction',
            'scifi' => 'Science Fiction',
            'sf' => 'Science Fiction',
            'dystopian' => 'Science Fiction',
            'cyberpunk' => 'Science Fiction',
            'space opera' => 'Science Fiction',

            // Fantasy variations
            'epic fantasy' => 'Fantasy',
            'urban fantasy' => 'Fantasy',
            'high fantasy' => 'Fantasy',
            'dark fantasy' => 'Fantasy',
            'sword and sorcery' => 'Fantasy',

            // Fiction variations
            'fiction' => 'General Fiction',
            'literary fiction' => 'General Fiction',
            'contemporary fiction' => 'General Fiction',
            'mystery' => 'General Fiction',
            'thriller' => 'Action',
            'suspense' => 'Action',
            'crime' => 'Action',
            'adventure' => 'Action',
            'war' => 'Action',

            // Historical
            'historical' => 'Historical Fiction',
            'biography' => 'History',
            'memoir' => 'History',
            'autobiography' => 'History',

            // Non-fiction variations
            'nonfiction' => 'Non Fiction',
            'self-help' => 'Non Fiction',
            'business' => 'Non Fiction',
            'philosophy' => 'Non Fiction',
            'psychology' => 'Non Fiction',
            'health' => 'Non Fiction',
            'politics' => 'Non Fiction',
            'economics' => 'Non Fiction',
            'true crime' => 'Non Fiction',

            // Religious
            'christian' => 'Religion',
            'spiritual' => 'Religion',
            'faith' => 'Religion',
            'biblical' => 'Religion',

            // Kids/Young Adult
            'children' => 'Kids',
            'juvenile' => 'Kids',
            'young adult' => 'Kids',
            'ya' => 'Kids',

            // Technology
            'technology' => 'Computer',
            'programming' => 'Computer',
            'it' => 'Computer',
            'tech' => 'Computer',

            // Classic literature
            'classics' => 'Classic',
            'literature' => 'Classic',
            'classic literature' => 'Classic',

            // LitRPG variations
            'gamelit' => 'LitRPG',
            'virtual reality' => 'LitRPG',
            'gaming' => 'LitRPG',
            'rpg' => 'LitRPG',

            // Generic terms that need better classification - remove these so AI picks better genres
            // 'audiobook' => 'Other',  // Let AI determine actual genre instead
            // 'book' => 'Other',      // Let AI determine actual genre instead
            // 'audio' => 'Other',     // Let AI determine actual genre instead
        ];

        // Check mapping (case-insensitive)
        $lowerAiGenre = strtolower($aiGenre);
        foreach ($genreMap as $pattern => $mappedGenre) {
            if (strpos($lowerAiGenre, strtolower($pattern)) !== false) {
                return $mappedGenre;
            }
        }

        // If no match found, default to 'Other'
        return 'Other';
    }
}
