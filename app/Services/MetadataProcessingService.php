<?php

namespace App\Services;

use App\Models\Author;
use App\Services\AIBookProcessor;
use App\Services\AudioFileAnalyzer;
use App\Traits\GenreMapping;
use Illuminate\Support\Facades\Log;

class MetadataProcessingService
{
    use GenreMapping;
    
    protected ?AIBookProcessor $aiProcessor = null;
    protected ?AudioFileAnalyzer $audioAnalyzer = null;

    public function __construct()
    {
        // Services will be injected lazily
    }

    /**
     * Process audiobook with AI analysis
     */
    public function processWithAI(array $audiobook): ?array
    {
        if (!$this->aiProcessor) {
            $this->aiProcessor = app(AIBookProcessor::class);
        }

        try {
            $result = $this->aiProcessor->extractMetadata($audiobook);
            
            if ($result && isset($result['confidence']) && $result['confidence'] >= 70) {
                return $this->postProcessAIResult($result, $audiobook);
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("AI processing failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Process audiobook with audio file analysis
     */
    public function processWithAudioAnalysis(array $audiobook): ?array
    {
        if (!$this->audioAnalyzer) {
            $this->audioAnalyzer = app(AudioFileAnalyzer::class);
        }

        try {
            $metadata = $this->audioAnalyzer->analyzeDirectory($audiobook['path']);
            
            if ($metadata) {
                return $this->postProcessAIResult($metadata, $audiobook);
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Audio analysis failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Post-process AI result with additional analysis
     */
    protected function postProcessAIResult(array $aiResult, array $audiobook): array
    {
        $metadata = $aiResult;
        
        // Add source path for GraphicAudio detection
        $metadata['source_path'] = $audiobook['path'];
        
        // Extract series number from title if not already done
        if (empty($metadata['series_number']) && !empty($metadata['title'])) {
            $this->extractSeriesNumberFromTitle($metadata);
        }
        
        // Map genre to valid values
        if (!empty($metadata['genre'])) {
            $genres = is_array($metadata['genre']) ? $metadata['genre'] : [$metadata['genre']];
            $mappedGenres = [];
            foreach ($genres as $genre) {
                $mappedGenres[] = $this->mapToValidGenre(trim($genre));
            }
            $metadata['genre'] = $mappedGenres;
        }
        
        return $metadata;
    }

    /**
     * Extract series number from title and clean the title
     */
    protected function extractSeriesNumberFromTitle(array &$metadata): void
    {
        if (empty($metadata['title'])) {
            return;
        }

        $title = trim($metadata['title']);
        
        $patterns = [
            '/^(.+?),\s*Book\s+(\d+)$/i',            // "Title, Book 1"
            '/^(.+?)\s+Book\s+(\d+)$/i',             // "Title Book 1"
            '/^(.+?),\s*Volume\s+(\d+)$/i',          // "Title, Volume 1"
            '/^(.+?)\s+Volume\s+(\d+)$/i',           // "Title Volume 1"
            '/^(.+?),\s*#(\d+)$/i',                  // "Title, #1"
            '/^(.+?)\s+#(\d+)$/i',                   // "Title #1"
            '/^(.+?),\s*Part\s+(\d+)$/i',            // "Title, Part 1"  
            '/^(.+?)\s+Part\s+(\d+)$/i',             // "Title Part 1"
            '/^(.+?)\s+(\d+)$/',                     // "Title 1" (last resort)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $cleanTitle = trim($matches[1]);
                $bookNumber = (int)$matches[2];

                $metadata['title'] = $cleanTitle;
                $metadata['series_number'] = $bookNumber;
                
                return;
            }
        }
    }

    /**
     * Detect multi-book pattern
     */
    public function detectMultiBookPattern(string $title): ?array
    {
        $patterns = [
            'books' => '/^(.+?)\s*books?\s*(\d+)\s*[-–]\s*(\d+)$/i',
            'parts' => '/^(.+?)\s*parts?\s*(\d+)\s*[-–]\s*(\d+)$/i',
            'volumes' => '/^(.+?)\s*volumes?\s*(\d+)\s*[-–]\s*(\d+)$/i',
            'episodes' => '/^(.+?)\s*episodes?\s*(\d+)\s*[-–]\s*(\d+)$/i',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, trim($title), $matches)) {
                return [
                    'type' => $type,
                    'series_name' => trim($matches[1]),
                    'start_number' => (int)$matches[2],
                    'end_number' => (int)$matches[3],
                    'count' => (int)$matches[3] - (int)$matches[2] + 1
                ];
            }
        }

        return null;
    }

    /**
     * Extract book title from filename
     */
    public function extractBookTitleFromFilename(string $filename, string $seriesName, int $bookNumber): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        
        $patterns = [
            "/^{$seriesName}\s*[-–]\s*book\s*{$bookNumber}\s*[-–]\s*(.+)$/i",
            "/^{$seriesName}\s*{$bookNumber}\s*[-–]\s*(.+)$/i",
            "/^(.+?)\s*[-–]\s*{$seriesName}\s*{$bookNumber}$/i",
            "/^(.+?)\s*[-–]\s*book\s*{$bookNumber}$/i",
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $baseName, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return "{$seriesName} {$bookNumber}";
    }

    /**
     * Get author's preferred genre based on existing books
     */
    public function getAuthorPreferredGenre($authorData): ?string
    {
        if (empty($authorData)) {
            return null;
        }
        
        // Handle both string and array author data
        $authorNames = is_array($authorData) ? $authorData : [$authorData];
        
        foreach ($authorNames as $authorName) {
            $authorName = trim($authorName);
            if (empty($authorName)) {
                continue;
            }
            
            // Find the author in the database
            $author = Author::where('name', $authorName)->first();
            if (!$author) {
                continue;
            }
            
            // Get genre distribution for this author's books
            $genreStats = \DB::table('books')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id')
                ->where('author_book.author_id', $author->id)
                ->select('genres.name', \DB::raw('COUNT(*) as count'))
                ->groupBy('genres.name')
                ->orderByDesc('count')
                ->first();
            
            if ($genreStats && $genreStats->count >= 2) {
                // If author has 2+ books in the same genre, use that genre
                return $genreStats->name;
            }
        }
        
        return null;
    }
}