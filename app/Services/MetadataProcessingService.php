<?php

namespace App\Services;

use App\Models\Author;
use App\Traits\GenreMapping;
use Illuminate\Support\Facades\DB;
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
            // Extract necessary data from audiobook array
            $directoryPath = $audiobook['path'] ?? '';
            $fileNames = array_map(function ($file) {
                return basename($file);
            }, $audiobook['files'] ?? []);

            // Extract file tags if available
            $fileTags = [];
            if (!empty($audiobook['files'])) {
                foreach ($audiobook['files'] as $file) {
                    if (
                        pathinfo($file, PATHINFO_EXTENSION) === 'm4b' ||
                        pathinfo($file, PATHINFO_EXTENSION) === 'mp3'
                    ) {
                        $tags = $this->aiProcessor->extractFileTags($file);
                        if (!empty($tags)) {
                            // Clean Graphic Audio suffixes from title tag
                            if (!empty($tags['title'])) {
                                $tags['title'] = preg_replace('/\s*\[Dramatized Adaptation\]\s*/i', '', $tags['title']);
                                $tags['title'] = preg_replace('/\s*\(Dramatized Adaptation\)\s*/i', '', $tags['title']);
                            }

                            // Extract author from comment field if present (e.g., "Written by Steven L. Kent")
                            if (!empty($tags['comment']) && empty($tags['author'])) {
                                if (preg_match('/Written by\s+([^.]+)/i', $tags['comment'], $matches)) {
                                    $tags['author'] = trim($matches[1]);
                                }
                            }

                            $fileTags = array_merge($fileTags, $tags);
                        }
                    }
                }
            }

            // Check for NFO data
            $nfoData = null;
            if (!empty($audiobook['nfo_data'])) {
                $nfoData = $audiobook['nfo_data'];
            }

            $result = $this->aiProcessor->processBookDirectory(
                $directoryPath,
                $fileNames,
                $fileTags,
                $nfoData
            );

            if ($result && isset($result['confidence']) && $result['confidence'] >= 70) {
                // Apply ID3 tag mappings if available: artist = author, composer = narrator, date = published_year
                $this->applyId3TagMappings($result, $fileTags);
                return $this->postProcessAIResult($result, $audiobook);
            }

            return null;
        } catch (\Exception $e) {
            Log::error("AI processing failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Process audiobook without AI - use only file metadata and OpenAudible data
     */
    public function processWithoutAI(array $audiobook): ?array
    {
        if (!$this->aiProcessor) {
            $this->aiProcessor = app(AIBookProcessor::class);
        }

        try {
            $metadata = [
                'title' => $audiobook['name'] ?? basename($audiobook['path']),
                'author' => [],
                'narrator' => [],
                'genre' => [],
                'year' => null,
                'publisher' => null,
                'description' => null,
                'series' => null,
                'series_number' => null,
                'confidence' => 50, // Low confidence since no AI validation
            ];

            // Extract file tags if available
            if (!empty($audiobook['files'])) {
                foreach ($audiobook['files'] as $file) {
                    $ext = pathinfo($file, PATHINFO_EXTENSION);
                    if ($ext === 'm4b' || $ext === 'mp3') {
                        $tags = $this->aiProcessor->extractFileTags($file);
                        if (!empty($tags)) {
                            // Extract metadata from tags
                            if (!empty($tags['title']) && empty($metadata['title'])) {
                                $metadata['title'] = $tags['title'];
                            }
                            if (!empty($tags['album'])) {
                                $metadata['title'] = preg_replace('/\s*\((Unabridged|Abridged)\)\s*$/i', '', $tags['album']);
                            }
                            if (!empty($tags['artist']) && empty($metadata['author'])) {
                                $metadata['author'] = is_array($tags['artist']) ? $tags['artist'] : [$tags['artist']];
                            }
                            if (!empty($tags['narrator']) && empty($metadata['narrator'])) {
                                $metadata['narrator'] = is_array($tags['narrator']) ? $tags['narrator'] : [$tags['narrator']];
                            }
                            if (!empty($tags['genre']) && empty($metadata['genre'])) {
                                $metadata['genre'] = is_array($tags['genre']) ? $tags['genre'] : [$tags['genre']];
                            }
                            if (!empty($tags['year']) && empty($metadata['year'])) {
                                $metadata['year'] = $tags['year'];
                            }
                            if (!empty($tags['publisher']) && empty($metadata['publisher'])) {
                                $metadata['publisher'] = $tags['publisher'];
                            }
                            if (!empty($tags['series']) && empty($metadata['series'])) {
                                $metadata['series'] = $tags['series'];
                            }
                            if (!empty($tags['part']) && empty($metadata['series_number'])) {
                                $metadata['series_number'] = is_numeric($tags['part']) ? (float) $tags['part'] : null;
                            }
                        }
                        break; // Only process first audio file
                    }
                }
            }

            // Post-process to merge OpenAudible data
            return $this->postProcessAIResult($metadata, $audiobook);
        } catch (\Exception $e) {
            Log::error("MetadataProcessingService: processWithoutAI failed", [
                'path' => $audiobook['path'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Process audiobook with audio file analysis
     */
    public function processWithAudioAnalysis(array $audiobook): ?array
    {
        try {
            // Use AIBookProcessor for real audio transcription (Whisper), not just ID3 tags
            $aiProcessor = app(AIBookProcessor::class);

            // Get first audio file (should already be sorted by Part1, Part2, etc.)
            $audioFilePath = $audiobook['files'][0] ?? null;

            if (!$audioFilePath || !file_exists($audioFilePath)) {
                Log::warning("No audio file found for transcription", [
                    'path' => $audiobook['path'] ?? 'unknown'
                ]);
                return null;
            }

            // Extract just the first 45 seconds for intro metadata
            $directoryHint = $audiobook['path'] ?? '';
            Log::info("Starting audio transcription for metadata extraction", [
                'file' => basename($audioFilePath),
                'directory_hint' => $directoryHint,
            ]);

            $metadata = $aiProcessor->processAudioSample($audioFilePath, $directoryHint);

            if ($metadata) {
                return $this->postProcessAIResult($metadata, $audiobook);
            }

            return null;
        } catch (\Exception $e) {
            Log::error("MetadataProcessingService: Audio transcription failed", [
                'path' => $audiobook['path'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Post-process AI result with additional analysis
     */
    protected function postProcessAIResult(array $aiResult, array $audiobook): array
    {
        $metadata = $aiResult;

        // Merge OpenAudible metadata if available (with priority to OpenAudible data)
        if (!empty($audiobook['openaudible_metadata'])) {
            $oaMeta = $audiobook['openaudible_metadata'];

            if (!empty($oaMeta['genre']) && (empty($metadata['genre']) || $metadata['confidence'] < 90)) {
                // OpenAudible uses hierarchical genres like "Science Fiction & Fantasy:Science Fiction"
                // We need to extract and map genres appropriately
                $genreStr = is_array($oaMeta['genre']) ? $oaMeta['genre'][0] : $oaMeta['genre'];
                $genreParts = explode(':', $genreStr);
                $rootCategory = trim($genreParts[0]);
                $specificGenre = trim(end($genreParts));

                // Build genre list
                $genres = [];

                // Add the specific subcategory as primary genre
                $genres[] = $specificGenre;

                // Add root category as secondary genre UNLESS:
                // 1. Root and specific are the same (no hierarchy)
                // 2. Specific genre perfectly maps to a main category (SF&F:Science Fiction or SF&F:Fantasy)
                $skipSecondary = (
                    $rootCategory === $specificGenre ||
                    ($rootCategory === 'Science Fiction & Fantasy' &&
                        ($specificGenre === 'Science Fiction' || $specificGenre === 'Fantasy'))
                );

                if (!$skipSecondary && count($genreParts) > 1) {
                    $genres[] = $rootCategory;
                }

                $metadata['genre'] = $genres;
            }

            if (!empty($oaMeta['series']) && empty($metadata['series'])) {
                $metadata['series'] = $oaMeta['series'];
            }

            if (!empty($oaMeta['series_number']) && empty($metadata['series_number'])) {
                $metadata['series_number'] = $oaMeta['series_number'];
            }

            if (!empty($oaMeta['narrator']) && empty($metadata['narrator'])) {
                $metadata['narrator'] = is_array($oaMeta['narrator']) ? $oaMeta['narrator'] : [$oaMeta['narrator']];
            }

            if (!empty($oaMeta['publisher']) && empty($metadata['publisher'])) {
                $metadata['publisher'] = $oaMeta['publisher'];
            }

            if (!empty($oaMeta['release_date']) && empty($metadata['year'])) {
                $year = substr($oaMeta['release_date'], 0, 4);
                if (is_numeric($year)) {
                    $metadata['year'] = $year;
                }
            }

            if (!empty($oaMeta['description']) && empty($metadata['description'])) {
                $metadata['description'] = $oaMeta['description'];
            }
        }

        // Add source path for GraphicAudio detection
        $metadata['source_path'] = $audiobook['path'];

        // Extract series number from title if not already done
        if (!empty($metadata['title'])) {
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

        // If genre is empty, invalid, or "Other", try to use author's preferred genre
        if (
            empty($metadata['genre']) ||
            (count($metadata['genre']) === 1 && in_array($metadata['genre'][0], ['Other', 'Unknown', 'Classic', 'Audiobook', null]))
        ) {
            $preferredGenre = $this->getAuthorPreferredGenre($metadata['author'] ?? null);
            if ($preferredGenre) {
                $metadata['genre'] = [$preferredGenre];
            }
        }

        return $metadata;
    }

    /**
     * Apply ID3 tag mappings to the result metadata
     * CRITICAL: File tags are AUTHORITATIVE - they override AI results
     */
    protected function applyId3TagMappings(array &$result, array $fileTags): void
    {
        // AUTHOR from artist/album_artist (raw, do NOT normalize here; tests expect raw value)
        if (!empty($fileTags['artist']) || !empty($fileTags['album_artist'])) {
            $artist = !empty($fileTags['artist']) ? (is_array($fileTags['artist']) ? (string) $fileTags['artist'][0] : (string) $fileTags['artist']) : (is_array($fileTags['album_artist']) ? (string) $fileTags['album_artist'][0] : (string) $fileTags['album_artist']);

            $artistLower = strtolower($artist);
            $hasGraphicAudio = str_contains($artistLower, 'graphic') && str_contains($artistLower, 'audio');
            $hasBracketedAuthor = (bool) preg_match('/\[[^\]]+\]/', $artist);

            // Skip setting author if it's just "Graphic Audio" (no bracketed actual author)
            if (!($hasGraphicAudio && !$hasBracketedAuthor)) {
                $result['author'] = [$artist];
            }
        }

        // NARRATOR from composer (tests expect a string here)
        if (!empty($fileTags['composer'])) {
            $composer = is_array($fileTags['composer']) ? (string) $fileTags['composer'][0] : (string) $fileTags['composer'];
            $result['narrator'] = $composer;
        }

        // YEAR from date (first 4 digits)
        if (!empty($fileTags['date']) && is_string($fileTags['date'])) {
            if (preg_match('/^(\d{4})/', $fileTags['date'], $m)) {
                $result['year'] = $m[1];
            }
        }

        // PUBLISHER from explicit tag or copyright (P)
        if (!empty($fileTags['publisher'])) {
            $result['publisher'] = is_array($fileTags['publisher']) ? $fileTags['publisher'][0] : $fileTags['publisher'];
        } elseif (!empty($fileTags['PUBLISHER'])) {
            $result['publisher'] = is_array($fileTags['PUBLISHER']) ? $fileTags['PUBLISHER'][0] : $fileTags['PUBLISHER'];
        } elseif (!empty($fileTags['copyright']) && is_string($fileTags['copyright'])) {
            if (preg_match('/\(P\)\d+\s+([^;]+)/i', $fileTags['copyright'], $matches)) {
                $result['publisher'] = trim($matches[1]);
            }
        }

        // GENRE (tests expect a string here)
        if (!empty($fileTags['genre'])) {
            $result['genre'] = is_array($fileTags['genre']) ? (string) $fileTags['genre'][0] : (string) $fileTags['genre'];
        }

        // SERIES and NUMBER from tags if present (authoritative: override AI values)
        $seriesTag = $fileTags['series'] ?? ($fileTags['SERIES'] ?? null);
        if (!empty($seriesTag)) {
            $result['series'] = is_array($seriesTag) ? $seriesTag[0] : $seriesTag;
        }
        $partTag = $fileTags['part'] ?? ($fileTags['PART'] ?? null);
        if (!empty($partTag)) {
            $partVal = is_array($partTag) ? $partTag[0] : $partTag;
            $result['series_number'] = is_numeric($partVal) ? (float) $partVal : null;
        }

        // TITLE parsing: extract series and number if encoded in title
        if (!empty($fileTags['title'])) {
            $title = is_array($fileTags['title']) ? (string) end($fileTags['title']) : (string) $fileTags['title'];
            // Remove "(Dramatized Adaptation)"
            $title = preg_replace('/\s*\(Dramatized Adaptation\)\s*/i', '', $title);
            // Remove narrator in parentheses at end
            $title = preg_replace('/\s*\([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+\)\s*$/i', '', $title);
            // Remove leading "Series [N]" block if present
            $title = preg_replace('/^(.+?)\s*\[[\d.]+\]\s*/', '', $title);

            $patterns = [
                '/^(.+?):\s*(.+?),\s*Book\s+([\d.]+)$/i',     // "Series: Title, Book N"
                '/^(.+?)\s+([\d.]+):\s*(.+)$/i',              // Series N: Title
                '/^(.+?)\s*[-–]\s*(.+?),\s*Book\s+([\d.]+)$/i',  // Title - Series, Book N
                '/^(.+?)\s*[-–]\s*(.+?)\s+Book\s+([\d.]+)$/i',   // Title - Series Book N
                '/^(.+?)\s*[-–]\s*(.+?)\s+([\d.]+)$/i',          // Title - Series N
            ];

            $matched = false;
            foreach ($patterns as $idx => $pattern) {
                if (preg_match($pattern, $title, $matches)) {
                    if ($idx === 0) {
                        // Series N: Title
                        $result['series'] = trim($matches[1]);
                        $result['series_number'] = (is_numeric($matches[2]) ? (float) $matches[2] : null);
                        $result['title'] = trim($matches[3]);
                    } else {
                        // Title - Series N variants
                        $result['title'] = trim($matches[1]);
                        $result['series'] = trim($matches[2]);
                        $result['series_number'] = (is_numeric($matches[3]) ? (float) $matches[3] : null);
                    }
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $result['title'] = $title;
            }
        }

        // DESCRIPTION
        if (!empty($fileTags['description']) && is_string($fileTags['description'])) {
            $result['description'] = strip_tags($fileTags['description']);
        } elseif (!empty($fileTags['comment']) && is_string($fileTags['comment'])) {
            $result['description'] = strip_tags($fileTags['comment']);
        }
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
        $title = $this->stripTrailingTitleQualifiers($title);

        $originalTitle = $title;

        if (preg_match('/^(.*?)\s*\[\s*([^\]]+)\s*\]\s*$/', $title, $matches)) {
            $possibleTitle = trim($matches[1]);
            $bracketContent = trim($matches[2]);
            $bracketContent = $this->stripTrailingTitleQualifiers($bracketContent);

            if (
                preg_match(
                    '/^(.*?)\s*(?:#|book|volume|vol\.?|part)\s*([\d.]+)\s*$/i',
                    $bracketContent,
                    $bracketMatches
                )
            ) {
                $seriesName = trim($bracketMatches[1]);
                $seriesNumber = $this->parseSeriesNumber($bracketMatches[2]);

                if ($seriesName !== '' && empty($metadata['series'])) {
                    $metadata['series'] = $seriesName;
                }
                if ($seriesNumber !== null && empty($metadata['series_number'])) {
                    $metadata['series_number'] = $seriesNumber;
                }

                if ($possibleTitle !== '') {
                    $title = $possibleTitle;
                }
            }
        }

        $patterns = [
            // Patterns with series name: "Title - Series Name, Book N"
            '/^(.+?)\s*[-–—]\s*(.+?),\s*Book\s+([\d.]+)$/i',     // "Title - Series, Book 1"
            '/^(.+?)\s*[-–—]\s*(.+?),\s*Volume\s+([\d.]+)$/i',   // "Title - Series, Volume 1"
            '/^(.+?)\s*[-–—]\s*(.+?),\s*Part\s+([\d.]+)$/i',     // "Title - Series, Part 1"
            '/^(.+?)\s*[-–—]\s*(.+?),\s*#([\d.]+)$/i',           // "Title - Series, #1"

            // Simple patterns: "Title, Book N"
            '/^(.+?),\s*Book\s+([\d.]+)$/i',            // "Title, Book 1"
            '/^(.+?)\s+Book\s+([\d.]+)$/i',             // "Title Book 1"
            '/^(.+?),\s*Volume\s+([\d.]+)$/i',          // "Title, Volume 1"
            '/^(.+?)\s+Volume\s+([\d.]+)$/i',           // "Title Volume 1"
            '/^(.+?),\s*#([\d.]+)$/i',                  // "Title, #1"
            '/^(.+?)\s+#([\d.]+)$/i',                   // "Title #1"
            '/^(.+?),\s*Part\s+([\d.]+)$/i',            // "Title, Part 1"
            '/^(.+?)\s+Part\s+([\d.]+)$/i',             // "Title Part 1"
            '/^(.+?)\s+([\d.]+)$/',                     // "Title 1" (last resort)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $cleanTitle = $this->stripTrailingTitleQualifiers(trim($matches[1]));

                // Check if this pattern includes series name (3 groups) or not (2 groups)
                if (count($matches) === 4) {
                    // Pattern with series name: "Title - Series, Book N"
                    $seriesName = trim($matches[2]);
                    $bookNumber = $this->parseSeriesNumber($matches[3]);

                    $metadata['title'] = $cleanTitle;
                    if (empty($metadata['series'])) {
                        $metadata['series'] = $seriesName;
                    }
                    $metadata['series_number'] = $bookNumber;
                } else {
                    // Pattern without series name: "Title, Book N"
                    $bookNumber = $this->parseSeriesNumber($matches[2]);

                    $metadata['title'] = $cleanTitle;
                    $metadata['series_number'] = $bookNumber;
                }

                return;
            }
        }

        if ($title !== $originalTitle) {
            $metadata['title'] = $title;
        }
    }

    private function parseSeriesNumber(string $value): int|float|null
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        if (str_contains($trimmed, '.')) {
            return (float) $trimmed;
        }

        return (int) $trimmed;
    }

    private function stripTrailingTitleQualifiers(string $title): string
    {
        $current = trim($title);
        $patterns = [
            '/\s*\((?:unabridged|abridged|complete|full\s*cast|dramati[sz]ed\s*adaptation|enhanced|remastered|special\s*edition|revised\s*edition|anniversary\s*edition)\)\s*$/i',
            '/\s*\[(?:unabridged|abridged|complete|full\s*cast|dramati[sz]ed\s*adaptation|enhanced|remastered|special\s*edition|revised\s*edition|anniversary\s*edition)\]\s*$/i',
        ];

        do {
            $previous = $current;
            foreach ($patterns as $pattern) {
                $current = preg_replace($pattern, '', $current);
                $current = trim($current);
            }
        } while ($current !== $previous);

        return $current;
    }

    /**
     * Detect multi-book pattern
     * Returns null if title ends with '^' (disables multi-book detection)
     */
    public function detectMultiBookPattern(string $title): ?array
    {
        // Check for '^' suffix which disables multi-book detection
        if (str_ends_with(trim($title), '^')) {
            return null;
        }

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
                    'start_number' => (int) $matches[2],
                    'end_number' => (int) $matches[3],
                    'count' => (int) $matches[3] - (int) $matches[2] + 1
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

        // Split comma-separated author strings
        $splitAuthors = [];
        foreach ($authorNames as $authorName) {
            if (strpos($authorName, ',') !== false) {
                // This is a comma-separated list of authors, split it
                $split = array_map('trim', explode(',', $authorName));
                $splitAuthors = array_merge($splitAuthors, $split);
            } else {
                $splitAuthors[] = $authorName;
            }
        }

        foreach ($splitAuthors as $authorName) {
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
            $genreStats = DB::table('books')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id')
                ->where('author_book.author_id', $author->id)
                ->select('genres.name', DB::raw('COUNT(*) as count'))
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

    /**
     * Normalize author name - extract from brackets and remove invalid patterns
     *
     * CRITICAL: Author will NEVER contain "Graphic" AND "Audio" - this is always invalid
     */
    protected function normalizeAuthorName(string $authorName): string
    {
        $name = trim($authorName);

        // Pattern: "Publisher/Narrator [Actual Author]"
        if (preg_match('/^.+?\s*\[([^\]]+)\]$/', $name, $matches)) {
            $name = trim($matches[1]);
        }

        // CRITICAL: If author contains both "Graphic" and "Audio", it's INVALID
        // This should NEVER be an author - it's a narrator/publisher
        if (stripos($name, 'graphic') !== false && stripos($name, 'audio') !== false) {
            return '';
        }

        // If it's just "Full Cast", return empty (narrator, not author)
        if (preg_match('/^Full\s*Cast$/i', $name)) {
            return '';
        }

        // Normalize initials
        $name = preg_replace('/\b([A-Z])\s+/', '$1. ', $name);
        $name = preg_replace('/\s+([A-Z])$/', ' $1.', $name);
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);

        return trim($name);
    }
}
